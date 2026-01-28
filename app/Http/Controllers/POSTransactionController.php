<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * POS Transaction Controller
 * ============================================================================
 *
 * Handles transaction uploads from POS.
 * Based on lines4/ajax_add_pos_transactions.php logic.
 *
 * ============================================================================
 */
class POSTransactionController extends Controller
{
    // Cash family ID range starts here (incrementing per cash customer)
    private const CASH_FAMILY_ID_START = 9500000;

    // Cached cash student for this request
    private ?object $cashStudentCache = null;
    private bool $cashStudentChecked = false;

    /**
     * POST /pos/transactions
     *
     * Upload transactions from POS.
     * Handles both regular students and cash transactions (studentId starts with "C").
     */
    public function store(Request $request): JsonResponse
    {
        // Debug: log incoming data
        \Log::info('Transaction sync request:', [
            'count' => count($request->transactions ?? []),
            'first_transaction' => $request->transactions[0] ?? null,
        ]);

        $request->validate([
            'transactions' => 'required|array',
            'transactions.*.syncKey' => 'required|string|max:64',  // {lineLogId}-{sessionId}-{localId}
            'transactions.*.localId' => 'required|integer',
            'transactions.*.userId' => 'nullable',  // User ID from frontend (can be int or string)
            'transactions.*.studentId' => 'required',  // Can be int (12345) or string ("C100")
            'transactions.*.itemId' => 'required',  // Can be string like "01" from POS
            'transactions.*.price' => 'required|numeric',
            'transactions.*.lineDate' => 'required|date_format:Y-m-d',
            'transactions.*.lineLogId' => 'required|integer',
            'transactions.*.stationSessionId' => 'required|integer',
            'transactions.*.mealType' => 'nullable|string|max:1',
            'transactions.*.lineNum' => 'nullable|integer',
            'transactions.*.transactionCode' => 'nullable|string|max:1',
            'transactions.*.itemType' => 'nullable|string|max:1',  // M, C, A, etc.
        ]);

        // Get user ID from session
        $token = $request->bearerToken();
        $session = \App\Models\StationSession::findByToken($token);
        $userId = $session ? $session->fldUserId : null;

        $results = [];
        $hasCashError = false;
        $cashErrorMessage = null;

        DB::beginTransaction();

        try {
            foreach ($request->transactions as $tx) {
                // Use line info from transaction (POS knows which line)
                $mealType = $tx['mealType'] ?? 'L';
                $lineNum = $tx['lineNum'] ?? 10;
                $stationStudentId = (string) $tx['studentId'];

                // Check for duplicate by syncKey
                $existing = DB::table('ww_pos_transactions')
                    ->where('fldSyncKey', $tx['syncKey'])
                    ->first();

                if ($existing) {
                    // Already exists - return existing server ID
                    $results[] = [
                        'localId' => $tx['localId'],
                        'syncKey' => $tx['syncKey'],
                        'serverId' => $existing->fldId,
                        'success' => true,
                        'duplicate' => true,
                    ];
                    continue;
                }

                // Check if this is a cash transaction (studentId starts with "C")
                $isCashTransaction = str_starts_with(strtoupper($stationStudentId), 'C');

                if ($isCashTransaction) {
                    // Cash transaction - use special cash student
                    try {
                        $cashStudent = $this->getCashStudent();
                    } catch (\Exception $e) {
                        // Cash student not configured - skip this transaction but continue with others
                        $hasCashError = true;
                        $cashErrorMessage = $e->getMessage();

                        $results[] = [
                            'localId' => $tx['localId'],
                            'syncKey' => $tx['syncKey'],
                            'serverId' => null,
                            'success' => false,
                            'error' => 'CASH_STUDENT_NOT_CONFIGURED',
                            'errorMessage' => 'Cash Student account not configured in database. Contact administrator.',
                        ];
                        continue;
                    }

                    // Get next available family ID for cash transactions on this line/day
                    $nextFamilyId = $this->getNextCashFamilyId($tx['lineDate'], $mealType);

                    $student = (object) [
                        'student_account_id' => $cashStudent->fldCloudId,
                        'family_account_id' => $nextFamilyId,
                        'school_id' => null,
                        'approval_method' => null,
                        'approval_code' => null,
                    ];
                } else {
                    // Regular student - look up by cloud ID
                    $student = DB::table('ww_student')
                        ->select([
                            'ww_student.fldCloudId as student_account_id',
                            'ww_family.fldFamPermId as family_account_id',
                            'ww_student.fldSchool as school_id',
                            'ww_student_status.fldStatus as student_status',
                            'ww_student_status.fldApprovalMethod as approval_method',
                            'ww_student_status.fldApprovalCode as approval_code',
                        ])
                        ->join('ww_family', 'ww_family.fldFamPermId', '=', 'ww_student.fldFamPermId')
                        ->leftJoin('ww_student_status', 'ww_student_status.fldStatusId', '=', 'ww_student.fldStatusId')
                        ->where('ww_student.fldCloudId', (int) $tx['studentId'])
                        ->first();

                    if (!$student) {
                        \Log::warning("Transaction sync: Student {$tx['studentId']} not found in database");
                        $student = (object) [
                            'student_account_id' => (int) $tx['studentId'],
                            'family_account_id' => $tx['familyId'] ?? null,
                            'school_id' => $tx['schoolCode'] ?? null,
                            'approval_method' => null,
                            'approval_code' => null,
                        ];
                    }
                }

                // Get item info (optional - use POS data if not found)
                $itemIdInt = (int) $tx['itemId'];
                $item = DB::table('ww_menuitem')
                    ->where('fldItemId', $itemIdInt)
                    ->first();

                // fldMealType = item type (M=Meal, C=Cash/A la carte, A=Adult, etc.)
                // fldTransactionCode = billing type (F=Free, R=Reduced, P=Paid, C=Cash, etc.)
                $transactionCode = $tx['transactionCode'] ?? ($isCashTransaction ? 'C' : ($item->fldItemType ?? 'C'));
                $itemType = $tx['itemType'] ?? ($item->fldItemType ?? 'C');

                // Approval method/code only applies to reimbursable meals (L, B, A, X)
                // Not for C (a la carte), M (milk), G (guest), S (staff) type items
                $isReimbursableMeal = in_array($itemType, ['L', 'B', 'A', 'X']);
                $approvalMethod = $isReimbursableMeal ? $student->approval_method : null;
                $approvalCode = $isReimbursableMeal ? $student->approval_code : null;

                // Use numeric userId from transaction, or fall back to session userId
                $txUserId = isset($tx['userId']) && is_numeric($tx['userId']) ? (int) $tx['userId'] : $userId;

                // Insert transaction
                $serverId = DB::table('ww_pos_transactions')->insertGetId([
                    'fldUserId' => $txUserId,
                    'fldStudentId' => (int) $student->student_account_id,
                    'fldFamPermId' => $student->family_account_id ? (int) $student->family_account_id : null,
                    'fldSchool' => $student->school_id,
                    'fldItemId' => $itemIdInt,
                    'fldMealType' => $itemType,
                    'fldTransactionCode' => $transactionCode,
                    'fldApprovalMethod' => $approvalMethod,
                    'fldApprovalCode' => $approvalCode,
                    'fldLineType' => $mealType,
                    'fldPrice' => $tx['price'],
                    'fldLineNum' => $lineNum,
                    'fldLineDate' => $tx['lineDate'],
                    'fldTransactionTimestampUTC' => isset($tx['timestampUTC']) ? Carbon::parse($tx['timestampUTC']) : Carbon::now('UTC'),
                    'fldPosId' => $lineNum,
                    'fldSyncKey' => $tx['syncKey'],
                    'fldStationStudentId' => $stationStudentId,
                    'fldCreatedDate' => Carbon::now('UTC'),
                    'fldFixed' => 1,
                ]);

                $results[] = [
                    'localId' => $tx['localId'],
                    'syncKey' => $tx['syncKey'],
                    'serverId' => $serverId,
                    'success' => true,
                ];
            }

            DB::commit();

            $response = [
                'success' => true,
                'results' => $results,
            ];

            // Add warning if cash transactions failed due to missing configuration
            if ($hasCashError) {
                $response['warning'] = 'CASH_STUDENT_NOT_CONFIGURED';
                $response['warningMessage'] = $cashErrorMessage;
                $response['cashTransactionsFailed'] = true;
            }

            return response()->json($response);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the cash student record (fldLcsId = 999999999)
     * This is the special "CASH STUDENT" used for anonymous cash transactions.
     *
     * @throws \Exception if cash student not found
     */
    private function getCashStudent(): object
    {
        // Return cached result if already checked
        if ($this->cashStudentChecked) {
            if (!$this->cashStudentCache) {
                throw $this->cashStudentNotFoundException();
            }
            return $this->cashStudentCache;
        }

        $this->cashStudentChecked = true;

        // Look up by the legacy pattern: fldLcsId = 999999999
        $this->cashStudentCache = DB::table('ww_student')
            ->select('fldCloudId', 'fldFamPermId', 'fldLcsId')
            ->where('fldLcsId', 999999999)
            ->first();

        if (!$this->cashStudentCache) {
            throw $this->cashStudentNotFoundException();
        }

        return $this->cashStudentCache;
    }

    /**
     * Create exception for missing cash student configuration
     */
    private function cashStudentNotFoundException(): \Exception
    {
        return new \Exception(
            'CASH_STUDENT_NOT_CONFIGURED: This database is missing the required Cash Student record. ' .
            'Cash transactions cannot be synced until a student record with fldLcsId = 999999999 is created. ' .
            'Please contact your system administrator to configure the Cash Student account. ' .
            'Non-cash transactions will continue to sync normally.'
        );
    }

    /**
     * Get next available family ID for cash transactions
     * Starts at 9500000 and increments for each cash customer on the line/day
     *
     * @throws \Exception if cash student not configured
     */
    private function getNextCashFamilyId(string $lineDate, string $mealType): int
    {
        // getCashStudent() will throw if not configured
        $cashStudent = $this->getCashStudent();

        $maxFamilyId = DB::table('ww_pos_transactions')
            ->where('fldStudentId', $cashStudent->fldCloudId)
            ->where('fldLineDate', $lineDate)
            ->where('fldLineType', $mealType)
            ->where('fldFamPermId', '>=', self::CASH_FAMILY_ID_START)
            ->max('fldFamPermId');

        return $maxFamilyId ? $maxFamilyId + 1 : self::CASH_FAMILY_ID_START;
    }

    /**
     * POST /pos/deletions
     *
     * Upload deletion audit log from POS.
     * Finds original transaction by syncKey, copies to delete log, removes original.
     */
    public function storeDeletions(Request $request): JsonResponse
    {
        $request->validate([
            'deletions' => 'required|array',
            'deletions.*.syncKey' => 'required|string|max:64',
            'deletions.*.originalSyncKey' => 'required|string|max:64',
            'deletions.*.tableName' => 'required|string|in:transactions,payments',
            'deletions.*.localId' => 'required|integer',
        ]);

        // Get user ID from session
        $token = $request->bearerToken();
        $session = \App\Models\StationSession::findByToken($token);
        $userId = $session ? $session->fldUserId : null;

        $results = [];

        DB::beginTransaction();

        try {
            foreach ($request->deletions as $del) {
                // Check for duplicate deletion by syncKey
                $existing = DB::table('ww_pos_transactions_delete_log')
                    ->where('fldSyncKey', $del['syncKey'])
                    ->first();

                if ($existing) {
                    $results[] = [
                        'localId' => $del['localId'],
                        'syncKey' => $del['syncKey'],
                        'serverId' => $existing->fldId,
                        'success' => true,
                        'duplicate' => true,
                    ];
                    continue;
                }

                // Find original transaction by syncKey
                $original = DB::table('ww_pos_transactions')
                    ->where('fldSyncKey', $del['originalSyncKey'])
                    ->first();

                if (!$original) {
                    // Transaction not found on server (maybe never synced, or already deleted)
                    $results[] = [
                        'localId' => $del['localId'],
                        'syncKey' => $del['syncKey'],
                        'serverId' => null,
                        'success' => true,
                        'notFound' => true,
                    ];
                    continue;
                }

                // Copy transaction to delete log
                $serverId = DB::table('ww_pos_transactions_delete_log')->insertGetId([
                    'fldDeletingUserId' => $userId,
                    'fldDeletedDate' => Carbon::now('UTC'),
                    'fldUserId' => $original->fldUserId,
                    'fldStudentId' => $original->fldStudentId,
                    'fldFamPermId' => $original->fldFamPermId,
                    'fldSchool' => $original->fldSchool,
                    'fldItemId' => $original->fldItemId,
                    'fldMealType' => $original->fldMealType,
                    'fldPrice' => $original->fldPrice,
                    'fldLineNum' => $original->fldLineNum,
                    'fldTransactionCode' => $original->fldTransactionCode,
                    'fldApprovalMethod' => $original->fldApprovalMethod,
                    'fldApprovalCode' => $original->fldApprovalCode,
                    'fldLineType' => $original->fldLineType,
                    'fldLineDate' => $original->fldLineDate,
                    'fldTransactionTimestampUTC' => $original->fldTransactionTimestampUTC,
                    'fldPosId' => $original->fldPosId,
                    'fldAjaxId' => $original->fldAjaxId,
                    'fldSyncKey' => $del['syncKey'],
                    'fldCreatedDate' => Carbon::now('UTC'),
                ]);

                // Delete original transaction
                DB::table('ww_pos_transactions')
                    ->where('fldId', $original->fldId)
                    ->delete();

                $results[] = [
                    'localId' => $del['localId'],
                    'syncKey' => $del['syncKey'],
                    'serverId' => $serverId,
                    'success' => true,
                ];
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract value from ability string like "line:10" -> "10"
     */
    private function extractAbilityValue(array $abilities, string $prefix): ?string
    {
        foreach ($abilities as $ability) {
            if (str_starts_with($ability, $prefix . ':')) {
                return explode(':', $ability)[1];
            }
        }
        return null;
    }
}
