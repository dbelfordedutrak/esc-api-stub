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
 *
 * ============================================================================
 */
class POSPaymentController extends Controller
{
    /**
     * POST /pos/payments
     *
     * Upload payments from POS.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'payments' => 'required|array',
            'payments.*.ajaxId' => 'required|string',
            'payments.*.studentId' => 'required',
            'payments.*.paymentType' => 'required|string|in:CASH,CHECK,CREDIT',
            'payments.*.amount' => 'required|numeric',
            'payments.*.lineDate' => 'required|date_format:Y-m-d',
        ]);

        // Extract line info from token abilities
        $abilities = $request->user()->currentAccessToken()->abilities;
        $lineNum = $this->extractAbilityValue($abilities, 'line');
        $mealType = $this->extractAbilityValue($abilities, 'meal');

        $userId = $request->user()->id;
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($request->payments as $pmt) {
                // Check for duplicate by ajaxId
                $existing = DB::table('ww_pos_payments')
                    ->where('fldAjaxId', $pmt['ajaxId'])
                    ->first();

                if ($existing) {
                    // Already exists - return existing server ID
                    $results[] = [
                        'localId' => $pmt['localId'] ?? null,
                        'ajaxId' => $pmt['ajaxId'],
                        'serverId' => $existing->fldId,
                        'success' => true,
                        'duplicate' => true,
                    ];
                    continue;
                }

                // Get student/family info
                $student = DB::table('ww_student')
                    ->select([
                        'ww_student.fldCloudId as student_account_id',
                        'ww_family.fldFamPermId as family_account_id',
                        'ww_student.fldSchool as school_id',
                    ])
                    ->join('ww_family', 'ww_family.fldFamPermId', '=', 'ww_student.fldFamPermId')
                    ->where('ww_student.fldCloudId', $pmt['studentId'])
                    ->first();

                if (!$student) {
                    $results[] = [
                        'localId' => $pmt['localId'] ?? null,
                        'ajaxId' => $pmt['ajaxId'],
                        'success' => false,
                        'error' => "Student {$pmt['studentId']} not found",
                    ];
                    continue;
                }

                // Insert payment
                $serverId = DB::table('ww_pos_payments')->insertGetId([
                    'fldUserId' => $userId,
                    'fldStudentId' => $student->student_account_id,
                    'fldFamPermId' => $student->family_account_id,
                    'fldSchool' => $student->school_id,
                    'fldPaymentType' => $pmt['paymentType'],
                    'fldAmount' => $pmt['amount'],
                    'fldCheckNumber' => $pmt['checkNumber'] ?? null,
                    'fldReferenceNumber' => $pmt['referenceNumber'] ?? null,
                    'fldLineNum' => $lineNum,
                    'fldLineDate' => $pmt['lineDate'],
                    'fldLineType' => $mealType,
                    'fldAjaxId' => $pmt['ajaxId'],
                    'fldCreatedDate' => Carbon::now('UTC'),
                ]);

                $results[] = [
                    'localId' => $pmt['localId'] ?? null,
                    'ajaxId' => $pmt['ajaxId'],
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
