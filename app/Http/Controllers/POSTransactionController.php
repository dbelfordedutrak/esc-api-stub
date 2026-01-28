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
    /**
     * POST /pos/transactions
     *
     * Upload transactions from POS.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'transactions' => 'required|array',
            'transactions.*.ajaxId' => 'required|string',
            'transactions.*.studentId' => 'required',
            'transactions.*.itemId' => 'required|string',
            'transactions.*.qty' => 'required|integer|min:1',
            'transactions.*.price' => 'required|numeric',
            'transactions.*.lineDate' => 'required|date_format:Y-m-d',
        ]);

        // Extract line info from token abilities
        $abilities = $request->user()->currentAccessToken()->abilities;
        $lineNum = $this->extractAbilityValue($abilities, 'line');
        $mealType = $this->extractAbilityValue($abilities, 'meal');

        $userId = $request->user()->id;
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($request->transactions as $tx) {
                // Check for duplicate by ajaxId
                $existing = DB::table('ww_pos_transactions')
                    ->where('fldAjaxId', $tx['ajaxId'])
                    ->first();

                if ($existing) {
                    // Already exists - return existing server ID
                    $results[] = [
                        'localId' => $tx['localId'] ?? null,
                        'ajaxId' => $tx['ajaxId'],
                        'serverId' => $existing->fldId,
                        'success' => true,
                        'duplicate' => true,
                    ];
                    continue;
                }

                // Get student info
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
                    ->where('ww_student.fldCloudId', $tx['studentId'])
                    ->first();

                if (!$student) {
                    $results[] = [
                        'localId' => $tx['localId'] ?? null,
                        'ajaxId' => $tx['ajaxId'],
                        'success' => false,
                        'error' => "Student {$tx['studentId']} not found",
                    ];
                    continue;
                }

                // Get item info
                $item = DB::table('ww_menuitem')
                    ->where('fldItemId', $tx['itemId'])
                    ->first();

                if (!$item) {
                    $results[] = [
                        'localId' => $tx['localId'] ?? null,
                        'ajaxId' => $tx['ajaxId'],
                        'success' => false,
                        'error' => "Item {$tx['itemId']} not found",
                    ];
                    continue;
                }

                // TODO: Calculate transaction code using calc_price_and_code logic
                // For now, use the code/price from the POS
                $transactionCode = $tx['transactionCode'] ?? $item->fldItemType;

                // Insert transaction
                $serverId = DB::table('ww_pos_transactions')->insertGetId([
                    'fldUserId' => $userId,
                    'fldStudentId' => $student->student_account_id,
                    'fldFamPermId' => $student->family_account_id,
                    'fldSchool' => $student->school_id,
                    'fldItemId' => $tx['itemId'],
                    'fldMealType' => $item->fldItemType,
                    'fldTransactionCode' => $transactionCode,
                    'fldApprovalMethod' => $student->approval_method,
                    'fldApprovalCode' => $student->approval_code,
                    'fldLineType' => $mealType,
                    'fldPrice' => $tx['price'],
                    'fldLineNum' => $lineNum,
                    'fldLineDate' => $tx['lineDate'],
                    'fldTransactionTimestampUTC' => Carbon::now('UTC'),
                    'fldPosId' => $lineNum,
                    'fldAjaxId' => $tx['ajaxId'],
                    'fldCreatedDate' => Carbon::now('UTC'),
                    'fldFixed' => true,
                ]);

                $results[] = [
                    'localId' => $tx['localId'] ?? null,
                    'ajaxId' => $tx['ajaxId'],
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
     * POST /pos/deletions
     *
     * Upload deletion audit log from POS.
     */
    public function storeDeletions(Request $request): JsonResponse
    {
        $request->validate([
            'deletions' => 'required|array',
            'deletions.*.ajaxId' => 'required|string',
            'deletions.*.originalAjaxId' => 'required|string',
            'deletions.*.tableName' => 'required|string',
        ]);

        $results = [];

        DB::beginTransaction();

        try {
            foreach ($request->deletions as $del) {
                // Check for duplicate
                $existing = DB::table('ww_pos_transactions_delete_log')
                    ->where('fldAjaxId', $del['ajaxId'])
                    ->first();

                if ($existing) {
                    $results[] = [
                        'localId' => $del['localId'] ?? null,
                        'ajaxId' => $del['ajaxId'],
                        'serverId' => $existing->fldId,
                        'success' => true,
                        'duplicate' => true,
                    ];
                    continue;
                }

                // Insert deletion log
                $serverId = DB::table('ww_pos_transactions_delete_log')->insertGetId([
                    'fldAjaxId' => $del['ajaxId'],
                    'fldOriginalAjaxId' => $del['originalAjaxId'],
                    'fldTableName' => $del['tableName'],
                    'fldRecordData' => json_encode($del['recordData'] ?? []),
                    'fldDeletedBy' => $del['deletedBy'] ?? $request->user()->id,
                    'fldDeletedAt' => $del['deletedAt'] ?? Carbon::now('UTC'),
                    'fldCreatedDate' => Carbon::now('UTC'),
                ]);

                $results[] = [
                    'localId' => $del['localId'] ?? null,
                    'ajaxId' => $del['ajaxId'],
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
