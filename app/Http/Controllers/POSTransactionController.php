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

        // Get user ID from session (lookup user by username)
        $token = $request->bearerToken();
        $session = \App\Models\StationSession::findByToken($token);
        $userId = null;
        if ($session) {
            $sessionUser = $session->user();
            $userId = $sessionUser ? $sessionUser->fldUserId : null;
        }

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
                // Note: fldStationSessionId requires schema update - omit for now
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
                    // 'fldStationSessionId' => $session->fldId,  // TODO: Add column to ww_pos_transactions
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

        // Get user ID from session (lookup user by username)
        $token = $request->bearerToken();
        $session = \App\Models\StationSession::findByToken($token);
        $userId = null;
        if ($session) {
            $sessionUser = $session->user();
            $userId = $sessionUser ? $sessionUser->fldUserId : null;
        }

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
     * GET /pos/students/{studentId}/transactions
     *
     * Get transactions for a student (for cross-station sync).
     * Returns transactions from ww_pos_transactions for the given student,
     * filtered by lineDate and mealType.
     *
     * Used by frontend to lazy-merge transactions from other stations.
     */
    public function getStudentTransactions(Request $request, int $studentId): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'error' => 'No authorization token provided',
                ], 401);
            }

            $session = \App\Models\StationSession::findByToken($token);

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid or expired session',
                ], 401);
            }

            // Update last activity
            $session->updateLastActivity();

            // Get filter parameters
            $lineDate = $request->query('lineDate', Carbon::now()->format('Y-m-d'));
            $mealType = $request->query('mealType', 'L');

            // The studentId passed might be cloudId OR lcsId - we need cloudId for queries
            // Try to find the student and get their cloudId
            $student = DB::table('ww_student')
                ->where('fldCloudId', $studentId)
                ->orWhere('fldLcsId', $studentId)
                ->first(['fldCloudId', 'fldLcsId']);

            // Use cloudId for transaction queries (that's what's stored in fldStudentId)
            $cloudId = $student ? (int) $student->fldCloudId : $studentId;

            \Log::info("getStudentTransactions: input={$studentId}, resolved cloudId={$cloudId}, lineDate={$lineDate}, mealType={$mealType}");

        // Get station session ID to identify "other" stations
        // Note: Until fldStationSessionId is added to ww_pos_transactions/payments,
        // we can't accurately determine which station made each transaction.
        // For now, we use syncKey prefix matching as a workaround.
        $currentStationSessionId = $session->fldId;
        $currentStationId = $session->fldStationId;

        // Build a lookup of session ID → station ID for labeling
        // This maps historical sessions to their stations
        $sessionToStation = DB::table('ww_pos_station_sessions')
            ->pluck('fldStationId', 'fldId')
            ->toArray();

        // Build syncKey prefix for this station session
        // syncKey format: {lineLogId}-{stationSessionId}-{localId}
        // We match by the middle segment to identify our transactions
        $syncKeyPattern = "%-{$currentStationSessionId}-%";

        // Fetch transactions for this student (using cloudId)
        // Note: fldStationSessionId column doesn't exist yet, so we can't join to station tables
        $transactions = DB::table('ww_pos_transactions as t')
            ->leftJoin('ww_menuitem as m', 'm.fldItemId', '=', 't.fldItemId')
            ->where('t.fldStudentId', $cloudId)
            ->where('t.fldLineDate', $lineDate)
            ->where('t.fldLineType', $mealType)
            ->select([
                't.fldId as serverId',
                't.fldSyncKey as syncKey',
                't.fldStudentId as studentId',
                't.fldItemId as itemId',
                'm.fldDescription as itemName',  // ww_menuitem uses fldDescription, not fldName
                't.fldMealType as itemType',
                't.fldPrice as price',
                't.fldTransactionTimestampUTC as timestampUTC',
                't.fldCreatedDate as createdAt',
                't.fldLineNum as lineNum',
            ])
            ->orderBy('t.fldCreatedDate', 'asc')
            ->get();

        // Mark each transaction as local or remote based on syncKey pattern
        $formatted = $transactions->map(function ($tx) use ($currentStationSessionId, $currentStationId, $sessionToStation) {
            // Parse syncKey to check if it's from this station
            // Format: {lineLogId}-{stationSessionId}-{localId}
            $isOtherStation = true;  // Assume other station by default
            $txSessionId = null;
            $txStationId = null;
            if ($tx->syncKey) {
                $parts = explode('-', $tx->syncKey);
                if (count($parts) >= 2) {
                    $txSessionId = (int) $parts[1];
                    // Look up which station this session belongs to
                    $txStationId = $sessionToStation[$txSessionId] ?? null;
                    // It's our station if the station ID matches (not session ID)
                    if ($txStationId === $currentStationId) {
                        $isOtherStation = false;
                    }
                }
            }

            return [
                'serverId' => $tx->serverId,
                'syncKey' => $tx->syncKey,
                'studentId' => $tx->studentId,
                'itemId' => $tx->itemId,
                'itemName' => $tx->itemName ?? "Item {$tx->itemId}",
                'itemType' => $tx->itemType,
                'price' => (float) $tx->price,
                'timestampUTC' => $tx->timestampUTC,
                'createdAt' => $tx->createdAt,
                'stationSessionId' => $txSessionId,
                'stationName' => $isOtherStation ? "St{$txStationId}" : 'This Station',
                'stationId' => $txStationId,
                'isOtherStation' => $isOtherStation,
            ];
        });

        // Also get payments (using cloudId)
        // Note: ww_pos_payments doesn't have fldLineType, use fldMealType instead
        $payments = DB::table('ww_pos_payments as p')
            ->where('p.fldStudentId', $cloudId)
            ->where('p.fldLineDate', $lineDate)
            ->where('p.fldMealType', $mealType)
            ->select([
                'p.fldId as serverId',
                'p.fldSyncKey as syncKey',
                'p.fldStudentId as studentId',
                'p.fldMealType as paymentType',  // Use mealType as fallback
                'p.fldAmount as amount',
                'p.fldMemo as memo',
                'p.fldCreatedDate as createdAt',
            ])
            ->orderBy('p.fldCreatedDate', 'asc')
            ->get();

        $formattedPayments = $payments->map(function ($p) use ($currentStationSessionId, $currentStationId, $sessionToStation) {
            // Parse syncKey to check if it's from this station
            $isOtherStation = true;
            $pmtSessionId = null;
            $pmtStationId = null;
            if ($p->syncKey) {
                $parts = explode('-', $p->syncKey);
                if (count($parts) >= 2) {
                    $pmtSessionId = (int) $parts[1];
                    // Look up which station this session belongs to
                    $pmtStationId = $sessionToStation[$pmtSessionId] ?? null;
                    // It's our station if the station ID matches (not session ID)
                    if ($pmtStationId === $currentStationId) {
                        $isOtherStation = false;
                    }
                }
            }

            // Parse payment type from memo (format: "L4 CASH" or "B2 CHK 1234")
            $paymentType = 'CASH';
            if ($p->memo && stripos($p->memo, 'CHK') !== false) {
                $paymentType = 'CHECK';
            }

            return [
                'serverId' => $p->serverId,
                'syncKey' => $p->syncKey,
                'studentId' => $p->studentId,
                'paymentType' => $paymentType,
                'amount' => (float) $p->amount,
                'memo' => $p->memo,
                'createdAt' => $p->createdAt,
                'stationSessionId' => $pmtSessionId,
                'stationName' => $isOtherStation ? "St{$pmtStationId}" : 'This Station',
                'stationId' => $pmtStationId,
                'isOtherStation' => $isOtherStation,
                'isPayment' => true,
            ];
        });

        return response()->json([
            'success' => true,
            'transactions' => $formatted,
            'payments' => $formattedPayments,
            'currentStationSessionId' => $currentStationSessionId,
        ]);

        } catch (\Exception $e) {
            \Log::error('getStudentTransactions error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /pos/sync/validate
     *
     * Validate sync state - two-step approach:
     * 1. Quick count check (mode=count) - just compare totals
     * 2. Full compare (mode=full) - compare syncKeys if counts differ
     */
    public function validateSync(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'error' => 'No authorization token provided',
                ], 401);
            }

            $session = \App\Models\StationSession::findByToken($token);

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid or expired session',
                ], 401);
            }

            $request->validate([
                'studentId' => 'required|integer',
                'lineDate' => 'required|date_format:Y-m-d',
                'mealType' => 'required|string|size:1',
                'mode' => 'string|in:count,full',  // count = quick check, full = compare syncKeys
                'transactionCount' => 'integer',   // For count mode
                'paymentCount' => 'integer',       // For count mode
                'transactionSyncKeys' => 'array',  // For full mode
                'paymentSyncKeys' => 'array',      // For full mode
            ]);

            $studentId = $request->studentId;
            $lineDate = $request->lineDate;
            $mealType = $request->mealType;
            $mode = $request->mode ?? 'count';

            // Resolve cloudId (studentId might be cloudId or lcsId)
            $student = DB::table('ww_student')
                ->where('fldCloudId', $studentId)
                ->orWhere('fldLcsId', $studentId)
                ->first(['fldCloudId']);
            $cloudId = $student ? (int) $student->fldCloudId : $studentId;

            // Get server counts (using cloudId)
            $serverTxCount = DB::table('ww_pos_transactions')
                ->where('fldStudentId', $cloudId)
                ->where('fldLineDate', $lineDate)
                ->where('fldLineType', $mealType)
                ->count();

            $serverPmtCount = DB::table('ww_pos_payments')
                ->where('fldStudentId', $cloudId)
                ->where('fldLineDate', $lineDate)
                ->where('fldMealType', $mealType)
                ->count();

            // COUNT MODE - quick check
            // Since "in sync" means "all local made it to server", we can only confirm
            // sync if client has no transactions (nothing to validate).
            // Otherwise, need full mode to verify local transactions are on server.
            if ($mode === 'count') {
                $clientTxCount = $request->transactionCount ?? 0;
                $clientPmtCount = $request->paymentCount ?? 0;

                // Only "in sync" via count mode if client has nothing to validate
                $isInSync = ($clientTxCount === 0) && ($clientPmtCount === 0);

                return response()->json([
                    'success' => true,
                    'mode' => 'count',
                    'isInSync' => $isInSync,
                    'transactions' => [
                        'clientCount' => $clientTxCount,
                        'serverCount' => $serverTxCount,
                    ],
                    'payments' => [
                        'clientCount' => $clientPmtCount,
                        'serverCount' => $serverPmtCount,
                    ],
                ]);
            }

            // FULL MODE - compare syncKeys
            $clientTxKeys = $request->transactionSyncKeys ?? [];
            $clientPmtKeys = $request->paymentSyncKeys ?? [];

            // Get server syncKeys (using cloudId)
            $serverTxKeys = DB::table('ww_pos_transactions')
                ->where('fldStudentId', $cloudId)
                ->where('fldLineDate', $lineDate)
                ->where('fldLineType', $mealType)
                ->whereNotNull('fldSyncKey')
                ->pluck('fldSyncKey')
                ->toArray();

            $serverPmtKeys = DB::table('ww_pos_payments')
                ->where('fldStudentId', $cloudId)
                ->where('fldLineDate', $lineDate)
                ->where('fldMealType', $mealType)
                ->whereNotNull('fldSyncKey')
                ->pluck('fldSyncKey')
                ->toArray();

            // Find discrepancies
            $txMissingFromServer = array_values(array_diff($clientTxKeys, $serverTxKeys));
            $pmtMissingFromServer = array_values(array_diff($clientPmtKeys, $serverPmtKeys));
            $txMissingFromClient = array_values(array_diff($serverTxKeys, $clientTxKeys));
            $pmtMissingFromClient = array_values(array_diff($serverPmtKeys, $clientPmtKeys));

            // "In Sync" = all LOCAL transactions made it to server
            // We don't care about "missing from client" (those are other stations' transactions)
            $isInSync = empty($txMissingFromServer) && empty($pmtMissingFromServer);

            return response()->json([
                'success' => true,
                'mode' => 'full',
                'isInSync' => $isInSync,
                'transactions' => [
                    'clientCount' => count($clientTxKeys),
                    'serverCount' => count($serverTxKeys),
                    'missingFromServer' => $txMissingFromServer,
                    'missingFromClient' => $txMissingFromClient,
                ],
                'payments' => [
                    'clientCount' => count($clientPmtKeys),
                    'serverCount' => count($serverPmtKeys),
                    'missingFromServer' => $pmtMissingFromServer,
                    'missingFromClient' => $pmtMissingFromClient,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('validateSync error: ' . $e->getMessage());
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
