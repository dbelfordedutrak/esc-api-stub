<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * POS Payment Controller
 * ============================================================================
 *
 * Handles payment uploads from POS.
 * Supports both regular students and cash transactions (studentId starts with "C").
 *
 * ============================================================================
 */
class POSPaymentController extends Controller
{
    // Cached cash student for this request
    private ?object $cashStudentCache = null;
    private bool $cashStudentChecked = false;

    /**
     * POST /pos/payments
     *
     * Upload payments from POS.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'payments' => 'required|array',
            'payments.*.syncKey' => 'required|string|max:64',  // {lineLogId}-{sessionId}-{localId}
            'payments.*.localId' => 'required|integer',
            'payments.*.studentId' => 'required',  // Can be int (12345) or string ("C100")
            'payments.*.paymentType' => 'required|string',  // cash, check (case-insensitive)
            'payments.*.amount' => 'required|numeric',
            'payments.*.lineDate' => 'required|date_format:Y-m-d',
            'payments.*.lineLogId' => 'required|integer',
            'payments.*.stationSessionId' => 'required|integer',
            'payments.*.mealType' => 'nullable|string|size:1',
            'payments.*.lineNum' => 'nullable|integer',
            'payments.*.checkNumber' => 'nullable|string',  // For check payments (legacy)
            'payments.*.memo' => 'nullable|string',  // Check description/comment
            'payments.*.changeGiven' => 'nullable|numeric',
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
            foreach ($request->payments as $pmt) {
                // Use line info from payment (POS knows which line)
                $mealType = $pmt['mealType'] ?? 'L';
                $lineNum = $pmt['lineNum'] ?? 10;
                $stationStudentId = (string) $pmt['studentId'];

                // Check for duplicate by syncKey
                $existing = DB::table('ww_pos_payments')
                    ->where('fldSyncKey', $pmt['syncKey'])
                    ->first();

                if ($existing) {
                    // Already exists - return existing server ID
                    $results[] = [
                        'localId' => $pmt['localId'],
                        'syncKey' => $pmt['syncKey'],
                        'serverId' => $existing->fldId,
                        'success' => true,
                        'duplicate' => true,
                    ];
                    continue;
                }

                // Check if this is a cash transaction (studentId starts with "C")
                $isCashTransaction = str_starts_with(strtoupper($stationStudentId), 'C');

                if ($isCashTransaction) {
                    // Cash payment - use special cash student
                    try {
                        $cashStudent = $this->getCashStudent();
                    } catch (\Exception $e) {
                        // Cash student not configured - skip this payment but continue with others
                        $hasCashError = true;
                        $cashErrorMessage = $e->getMessage();

                        $results[] = [
                            'localId' => $pmt['localId'],
                            'syncKey' => $pmt['syncKey'],
                            'serverId' => null,
                            'success' => false,
                            'error' => 'CASH_STUDENT_NOT_CONFIGURED',
                            'errorMessage' => 'Cash Student account not configured in database. Contact administrator.',
                        ];
                        continue;
                    }

                    // For cash payments, look up the family ID from matching transaction
                    // (the transaction should have been synced first with the same stationStudentId)
                    $matchingTx = DB::table('ww_pos_transactions')
                        ->where('fldStationStudentId', $stationStudentId)
                        ->where('fldLineDate', $pmt['lineDate'])
                        ->where('fldLineType', $mealType)
                        ->first();

                    $familyId = $matchingTx ? $matchingTx->fldFamPermId : null;

                    $student = (object) [
                        'student_account_id' => $cashStudent->fldCloudId,
                        'family_account_id' => $familyId,
                        'school_id' => null,
                    ];
                } else {
                    // Regular student - look up by cloud ID
                    $student = DB::table('ww_student')
                        ->select([
                            'ww_student.fldCloudId as student_account_id',
                            'ww_family.fldFamPermId as family_account_id',
                            'ww_student.fldSchool as school_id',
                        ])
                        ->join('ww_family', 'ww_family.fldFamPermId', '=', 'ww_student.fldFamPermId')
                        ->where('ww_student.fldCloudId', (int) $pmt['studentId'])
                        ->first();

                    if (!$student) {
                        \Log::warning("Payment sync: Student {$pmt['studentId']} not found in database");
                        $student = (object) [
                            'student_account_id' => (int) $pmt['studentId'],
                            'family_account_id' => $pmt['familyId'] ?? null,
                            'school_id' => $pmt['schoolCode'] ?? null,
                        ];
                    }
                }

                // Determine if check or cash payment method
                $paymentType = strtoupper($pmt['paymentType']);
                $isCheck = ($paymentType === 'CHECK' || $paymentType === 'CHK') ? 1 : 0;

                // Build memo:
                // - For checks: "CHK {memo}" (no line prefix, max 18 chars)
                // - For cash: "LL CASH" where L=meal type, L=line digit
                if ($isCheck) {
                    $checkMemo = $pmt['memo'] ?? $pmt['checkNumber'] ?? '';
                    $memo = 'CHK ' . substr($checkMemo, 0, 14); // 18 - 4 for "CHK "
                } else {
                    $lineDigit = $lineNum % 10;
                    $memo = $mealType . $lineDigit . ' CASH';
                }

                // Insert payment
                // Note: fldLineType, fldStationSessionId require schema update - omit for now
                $serverId = DB::table('ww_pos_payments')->insertGetId([
                    'fldUserId' => $pmt['userId'] ?? $userId,
                    'fldStudentId' => (int) $student->student_account_id,
                    'fldFamPermId' => $student->family_account_id ? (int) $student->family_account_id : null,
                    'fldSchool' => $student->school_id,
                    'fldMealType' => $mealType,
                    'fldLineNum' => $lineNum,
                    'fldLineDate' => $pmt['lineDate'],
                    // 'fldLineType' => $mealType,  // TODO: Add column to ww_pos_payments
                    'fldAmount' => $pmt['amount'],
                    'fldChangeGiven' => $pmt['changeGiven'] ?? 0,
                    'fldMemo' => $memo,
                    'fldIsCheck' => $isCheck,
                    'fldPosId' => $lineNum,
                    'fldSyncKey' => $pmt['syncKey'],
                    'fldStationStudentId' => $stationStudentId,
                    // 'fldStationSessionId' => $session ? $session->fldId : null,  // TODO: Add column
                    'fldCreatedDate' => Carbon::now('UTC'),
                    'fldFixed' => 1,
                ]);

                $results[] = [
                    'localId' => $pmt['localId'],
                    'syncKey' => $pmt['syncKey'],
                    'serverId' => $serverId,
                    'success' => true,
                ];
            }

            DB::commit();

            $response = [
                'success' => true,
                'results' => $results,
            ];

            // Add warning if cash payments failed due to missing configuration
            if ($hasCashError) {
                $response['warning'] = 'CASH_STUDENT_NOT_CONFIGURED';
                $response['warningMessage'] = $cashErrorMessage;
                $response['cashPaymentsFailed'] = true;
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
            'Cash payments cannot be synced until a student record with fldLcsId = 999999999 is created. ' .
            'Please contact your system administrator to configure the Cash Student account. ' .
            'Non-cash payments will continue to sync normally.'
        );
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
