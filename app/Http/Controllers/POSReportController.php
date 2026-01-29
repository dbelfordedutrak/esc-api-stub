<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * POS Report Controller
 * ============================================================================
 *
 * Handles report generation for POS lines.
 *
 * Report Types:
 *   i - Sales By ID
 *   n - Sales By Name
 *   g - Sales By Grade
 *   r - Payments
 *   t - Tally Entry
 *   s - Student Seconds
 *   c - Cash & Check Detail
 *   d - Deleted Transactions
 *
 * ============================================================================
 */
class POSReportController extends Controller
{
    /**
     * GET /pos/reports/{mealType}/{lineNum}/{reportType}
     *
     * Generate a report for a specific line.
     */
    public function show(Request $request, string $mealType, int $lineNum, string $reportType): JsonResponse
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

            // Get parameters
            $date = $request->query('date', Carbon::now()->format('Y-m-d'));
            $userId = $request->query('user', '0'); // 0 = all users
            $cashFilter = $request->query('cashFilter', 'all'); // 'all', 'exclude', 'only'

            // Get current station info for marking local vs remote
            $currentStationId = $session->fldStationId;
            $currentSessionId = $session->fldId;

            // Build session-to-station lookup
            $sessionToStation = DB::table('ww_pos_station_sessions')
                ->pluck('fldStationId', 'fldId')
                ->toArray();

            // Route to appropriate report method
            switch ($reportType) {
                case 'i':
                    return $this->salesById($mealType, $lineNum, $date, $userId, $currentStationId, $sessionToStation, $cashFilter);
                case 'n':
                    return $this->salesByName($mealType, $lineNum, $date, $userId, $currentStationId, $sessionToStation, $cashFilter);
                case 'g':
                    return $this->salesByGrade($mealType, $lineNum, $date, $userId, $currentStationId, $sessionToStation, $cashFilter);
                case 'r':
                    return $this->payments($mealType, $lineNum, $date, $userId, $currentStationId, $sessionToStation);
                case 't':
                    $stationOnly = $request->query('stationOnly', '0') === '1';
                    return $this->tallyEntry($mealType, $lineNum, $date, $userId, $cashFilter, $stationOnly ? $currentStationId : null, $sessionToStation);
                case 's':
                    $includeMilk = $request->query('includeMilk', '0') === '1';
                    return $this->studentSeconds($mealType, $lineNum, $date, $userId, $includeMilk);
                case 'c':
                    return $this->cashCheckDetail($mealType, $lineNum, $date, $userId, $currentStationId, $sessionToStation);
                case 'd':
                    return $this->deletedTransactions($mealType, $lineNum, $date);
                default:
                    return response()->json([
                        'success' => false,
                        'error' => "Unknown report type: {$reportType}",
                    ], 400);
            }

        } catch (\Exception $e) {
            \Log::error('Report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sales By ID Report
     * List all transactions sorted by student ID
     */
    private function salesById(string $mealType, int $lineNum, string $date, string $userId, ?int $currentStationId, array $sessionToStation, string $cashFilter = 'all'): JsonResponse
    {
        $query = DB::table('ww_pos_transactions as t')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 't.fldStudentId')
            ->leftJoin('ww_menuitem as m', 'm.fldItemId', '=', 't.fldItemId')
            ->where('t.fldLineDate', $date)
            ->where('t.fldLineType', $mealType)
            ->where('t.fldLineNum', $lineNum);

        // Apply cash filter (cash student has fldLcsId = 999999999)
        if ($cashFilter === 'exclude') {
            $query->where(function ($q) {
                $q->where('s.fldLcsId', '!=', 999999999)
                  ->orWhereNull('s.fldLcsId');
            });
        } elseif ($cashFilter === 'only') {
            $query->where('s.fldLcsId', 999999999);
        }

        $query->select([
                't.fldId as id',
                't.fldSyncKey as syncKey',
                's.fldLcsId as studentId',
                's.fldLunchId as lunchId',
                's.fldReferenceId as referenceId',
                DB::raw("CONCAT(s.fldLastName, ', ', s.fldFirstName) as name"),
                's.fldSchool as school',
                's.fldGrade as grade',
                's.fldHomeroom as homeroom',
                't.fldUserId as cashierId',
                't.fldItemId as itemId',
                'm.fldDescription as description',
                't.fldPrice as price',
                't.fldCreatedDate as createdAt',
            ]);

        // Filter by user if specified
        if ($userId !== '0') {
            $query->where('t.fldUserId', $userId);
        }

        // Sort by student ID, then time
        $query->orderBy('s.fldLcsId', 'asc')
              ->orderBy('t.fldCreatedDate', 'asc');

        $transactions = $query->get();

        // Add station info and format
        $data = $transactions->map(function ($tx) use ($currentStationId, $sessionToStation) {
            $stationId = null;
            $isThisStation = false;

            if ($tx->syncKey) {
                $parts = explode('-', $tx->syncKey);
                if (count($parts) >= 2) {
                    $sessionId = (int) $parts[1];
                    $stationId = $sessionToStation[$sessionId] ?? null;
                    $isThisStation = ($stationId === $currentStationId);
                }
            }

            $isCash = ($tx->studentId == 999999999);
            return [
                'id' => $tx->id,
                'studentId' => $isCash ? 'CASH' : ($tx->studentId ?? 'CASH'),
                'lunchId' => $isCash ? 'CASH' : ($tx->lunchId ?? ''),
                'referenceId' => $isCash ? 'CASH' : ($tx->referenceId ?? ''),
                'name' => $tx->name ?? 'Cash Customer',
                'school' => $tx->school ?? '',
                'grade' => $tx->grade ?? '',
                'homeroom' => $tx->homeroom ?? '',
                'cashierId' => $tx->cashierId,
                'itemId' => $tx->itemId,
                'description' => $tx->description ?? "Item {$tx->itemId}",
                'price' => (float) $tx->price,
                'time' => Carbon::parse($tx->createdAt)->format('g:i A'),
                'stationId' => $stationId,
                'isThisStation' => $isThisStation,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'reportType' => 'i',
                'reportName' => 'Sales By ID',
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'date' => $date,
                'totalRecords' => $data->count(),
                'currentStationId' => $currentStationId,
                'cashFilter' => $cashFilter,
            ],
        ]);
    }

    /**
     * Sales By Name Report
     * List all transactions sorted by student name
     */
    private function salesByName(string $mealType, int $lineNum, string $date, string $userId, ?int $currentStationId, array $sessionToStation, string $cashFilter = 'all'): JsonResponse
    {
        // Same as salesById but sorted by name
        $query = DB::table('ww_pos_transactions as t')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 't.fldStudentId')
            ->leftJoin('ww_menuitem as m', 'm.fldItemId', '=', 't.fldItemId')
            ->where('t.fldLineDate', $date)
            ->where('t.fldLineType', $mealType)
            ->where('t.fldLineNum', $lineNum);

        // Apply cash filter
        if ($cashFilter === 'exclude') {
            $query->where(function ($q) {
                $q->where('s.fldLcsId', '!=', 999999999)
                  ->orWhereNull('s.fldLcsId');
            });
        } elseif ($cashFilter === 'only') {
            $query->where('s.fldLcsId', 999999999);
        }

        $query->select([
                't.fldId as id',
                't.fldSyncKey as syncKey',
                's.fldLcsId as studentId',
                's.fldLunchId as lunchId',
                's.fldReferenceId as referenceId',
                's.fldLastName as lastName',
                's.fldFirstName as firstName',
                DB::raw("CONCAT(s.fldLastName, ', ', s.fldFirstName) as name"),
                's.fldSchool as school',
                't.fldUserId as cashierId',
                't.fldItemId as itemId',
                'm.fldDescription as description',
                't.fldPrice as price',
                't.fldCreatedDate as createdAt',
            ]);

        if ($userId !== '0') {
            $query->where('t.fldUserId', $userId);
        }

        $query->orderBy('s.fldLastName', 'asc')
              ->orderBy('s.fldFirstName', 'asc')
              ->orderBy('t.fldCreatedDate', 'asc');

        $transactions = $query->get();

        $data = $transactions->map(function ($tx) use ($currentStationId, $sessionToStation) {
            $stationId = null;
            $isThisStation = false;

            if ($tx->syncKey) {
                $parts = explode('-', $tx->syncKey);
                if (count($parts) >= 2) {
                    $sessionId = (int) $parts[1];
                    $stationId = $sessionToStation[$sessionId] ?? null;
                    $isThisStation = ($stationId === $currentStationId);
                }
            }

            $isCash = ($tx->studentId == 999999999);
            return [
                'id' => $tx->id,
                'studentId' => $isCash ? 'CASH' : ($tx->studentId ?? 'CASH'),
                'lunchId' => $isCash ? 'CASH' : ($tx->lunchId ?? ''),
                'referenceId' => $isCash ? 'CASH' : ($tx->referenceId ?? ''),
                'name' => $tx->name ?? 'Cash Customer',
                'school' => $tx->school ?? '',
                'cashierId' => $tx->cashierId,
                'itemId' => $tx->itemId,
                'description' => $tx->description ?? "Item {$tx->itemId}",
                'price' => (float) $tx->price,
                'time' => Carbon::parse($tx->createdAt)->format('g:i A'),
                'stationId' => $stationId,
                'isThisStation' => $isThisStation,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'reportType' => 'n',
                'reportName' => 'Sales By Name',
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'date' => $date,
                'totalRecords' => $data->count(),
                'currentStationId' => $currentStationId,
                'cashFilter' => $cashFilter,
            ],
        ]);
    }

    /**
     * Sales By Grade Report
     */
    private function salesByGrade(string $mealType, int $lineNum, string $date, string $userId, ?int $currentStationId, array $sessionToStation, string $cashFilter = 'all'): JsonResponse
    {
        $query = DB::table('ww_pos_transactions as t')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 't.fldStudentId')
            ->leftJoin('ww_menuitem as m', 'm.fldItemId', '=', 't.fldItemId')
            ->where('t.fldLineDate', $date)
            ->where('t.fldLineType', $mealType)
            ->where('t.fldLineNum', $lineNum);

        // Apply cash filter
        if ($cashFilter === 'exclude') {
            $query->where(function ($q) {
                $q->where('s.fldLcsId', '!=', 999999999)
                  ->orWhereNull('s.fldLcsId');
            });
        } elseif ($cashFilter === 'only') {
            $query->where('s.fldLcsId', 999999999);
        }

        $query->select([
                't.fldId as id',
                't.fldSyncKey as syncKey',
                's.fldLcsId as studentId',
                's.fldLunchId as lunchId',
                's.fldReferenceId as referenceId',
                DB::raw("CONCAT(s.fldLastName, ', ', s.fldFirstName) as name"),
                's.fldSchool as school',
                's.fldGrade as grade',
                't.fldUserId as cashierId',
                't.fldItemId as itemId',
                'm.fldDescription as description',
                't.fldPrice as price',
                't.fldCreatedDate as createdAt',
            ]);

        if ($userId !== '0') {
            $query->where('t.fldUserId', $userId);
        }

        // Sort by grade (numeric), then name
        $query->orderByRaw('CAST(s.fldGrade AS UNSIGNED) ASC')
              ->orderBy('s.fldLastName', 'asc')
              ->orderBy('s.fldFirstName', 'asc')
              ->orderBy('t.fldCreatedDate', 'asc');

        $transactions = $query->get();

        $data = $transactions->map(function ($tx) use ($currentStationId, $sessionToStation) {
            $stationId = null;
            $isThisStation = false;

            if ($tx->syncKey) {
                $parts = explode('-', $tx->syncKey);
                if (count($parts) >= 2) {
                    $sessionId = (int) $parts[1];
                    $stationId = $sessionToStation[$sessionId] ?? null;
                    $isThisStation = ($stationId === $currentStationId);
                }
            }

            $isCash = ($tx->studentId == 999999999);
            return [
                'id' => $tx->id,
                'studentId' => $isCash ? 'CASH' : ($tx->studentId ?? 'CASH'),
                'lunchId' => $isCash ? 'CASH' : ($tx->lunchId ?? ''),
                'referenceId' => $isCash ? 'CASH' : ($tx->referenceId ?? ''),
                'name' => $tx->name ?? 'Cash Customer',
                'school' => $tx->school ?? '',
                'grade' => $tx->grade ?? '',
                'cashierId' => $tx->cashierId,
                'itemId' => $tx->itemId,
                'description' => $tx->description ?? "Item {$tx->itemId}",
                'price' => (float) $tx->price,
                'time' => Carbon::parse($tx->createdAt)->format('g:i A'),
                'stationId' => $stationId,
                'isThisStation' => $isThisStation,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'reportType' => 'g',
                'reportName' => 'Sales By Grade',
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'date' => $date,
                'totalRecords' => $data->count(),
                'currentStationId' => $currentStationId,
                'cashFilter' => $cashFilter,
            ],
        ]);
    }

    /**
     * Payments Report
     */
    private function payments(string $mealType, int $lineNum, string $date, string $userId, ?int $currentStationId, array $sessionToStation): JsonResponse
    {
        $query = DB::table('ww_pos_payments as p')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 'p.fldStudentId')
            ->where('p.fldLineDate', $date)
            ->where('p.fldMealType', $mealType)
            ->where('p.fldLineNum', $lineNum)
            ->select([
                'p.fldId as id',
                'p.fldSyncKey as syncKey',
                's.fldLcsId as studentId',
                's.fldFamPermId as familyId',
                DB::raw("CONCAT(s.fldLastName, ', ', s.fldFirstName) as name"),
                's.fldSchool as school',
                'p.fldUserId as cashierId',
                'p.fldAmount as amount',
                'p.fldMemo as memo',
                'p.fldIsCheck as isCheck',
                'p.fldCreatedDate as createdAt',
            ]);

        if ($userId !== '0') {
            $query->where('p.fldUserId', $userId);
        }

        $query->orderBy('s.fldLastName', 'asc')
              ->orderBy('s.fldFirstName', 'asc')
              ->orderBy('p.fldCreatedDate', 'asc');

        $payments = $query->get();

        $data = $payments->map(function ($p) use ($currentStationId, $sessionToStation) {
            $stationId = null;
            $isThisStation = false;

            if ($p->syncKey) {
                $parts = explode('-', $p->syncKey);
                if (count($parts) >= 2) {
                    $sessionId = (int) $parts[1];
                    $stationId = $sessionToStation[$sessionId] ?? null;
                    $isThisStation = ($stationId === $currentStationId);
                }
            }

            return [
                'id' => $p->id,
                'studentId' => $p->studentId ?? 'CASH',
                'familyId' => $p->familyId ?? '',
                'name' => $p->name ?? 'Cash Customer',
                'school' => $p->school ?? '',
                'cashierId' => $p->cashierId,
                'amount' => (float) $p->amount,
                'memo' => $p->memo ?? '',
                'isCheck' => (bool) $p->isCheck,
                'time' => Carbon::parse($p->createdAt)->format('g:i A'),
                'stationId' => $stationId,
                'isThisStation' => $isThisStation,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'reportType' => 'r',
                'reportName' => 'Payments',
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'date' => $date,
                'totalRecords' => $data->count(),
                'totalAmount' => $data->sum('amount'),
                'currentStationId' => $currentStationId,
            ],
        ]);
    }

    /**
     * Tally Entry Report - Summary of items sold
     */
    private function tallyEntry(string $mealType, int $lineNum, string $date, string $userId, string $cashFilter = 'all', ?int $stationId = null, array $sessionToStation = []): JsonResponse
    {
        // Get item counts grouped by item, separating cash vs charged
        $query = DB::table('ww_pos_transactions as t')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 't.fldStudentId')
            ->leftJoin('ww_menuitem as m', 'm.fldItemId', '=', 't.fldItemId')
            ->where('t.fldLineDate', $date)
            ->where('t.fldLineType', $mealType)
            ->where('t.fldLineNum', $lineNum);

        // Filter by station if requested
        if ($stationId !== null && !empty($sessionToStation)) {
            // Get session IDs that belong to this station
            $stationSessionIds = array_keys(array_filter($sessionToStation, fn($sid) => $sid === $stationId));
            if (!empty($stationSessionIds)) {
                $query->whereIn('t.fldSessionId', $stationSessionIds);
            } else {
                // No sessions for this station - return empty
                $query->whereRaw('1=0');
            }
        }

        // Apply cash filter
        if ($cashFilter === 'exclude') {
            $query->where(function ($q) {
                $q->where('s.fldLcsId', '!=', 999999999)
                  ->orWhereNull('s.fldLcsId');
            });
        } elseif ($cashFilter === 'only') {
            $query->where('s.fldLcsId', 999999999);
        }

        $query->select([
                't.fldItemId as itemId',
                'm.fldDescription as description',
                DB::raw("SUM(CASE WHEN s.fldLcsId = 999999999 OR s.fldLcsId IS NULL THEN 1 ELSE 0 END) as cashCount"),
                DB::raw("SUM(CASE WHEN s.fldLcsId != 999999999 AND s.fldLcsId IS NOT NULL THEN 1 ELSE 0 END) as chargedCount"),
            ])
            ->groupBy('t.fldItemId', 'm.fldDescription');

        if ($userId !== '0') {
            $query->where('t.fldUserId', $userId);
        }

        $query->orderBy('t.fldItemId', 'asc');

        $items = $query->get();

        $data = $items->map(function ($item) {
            return [
                'itemId' => $item->itemId,
                'description' => $item->description ?? "Item {$item->itemId}",
                'chargedCount' => (int) $item->chargedCount,
                'cashCount' => (int) $item->cashCount,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'reportType' => 't',
                'reportName' => 'Tally Entry',
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'date' => $date,
                'cashFilter' => $cashFilter,
            ],
        ]);
    }

    /**
     * Student Seconds - Students with more than one meal
     */
    private function studentSeconds(string $mealType, int $lineNum, string $date, string $userId, bool $includeMilk): JsonResponse
    {
        // Meal types to check
        $mealTypes = ['L', 'B', 'P', 'X'];
        if ($includeMilk) {
            $mealTypes = array_merge($mealTypes, ['M', 'Z']);
        }

        // Find students with 2+ meal transactions
        $query = DB::table('ww_pos_transactions as t')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 't.fldStudentId')
            ->leftJoin('ww_menuitem as m', 'm.fldItemId', '=', 't.fldItemId')
            ->where('t.fldLineDate', $date)
            ->where('t.fldLineType', $mealType)
            ->where('t.fldLineNum', $lineNum)
            ->whereIn('t.fldMealType', $mealTypes)
            ->whereNotNull('s.fldLcsId')
            ->where('s.fldLcsId', '!=', 999999999)
            ->select([
                't.fldId as id',
                's.fldCloudId as cloudId',
                's.fldLcsId as studentId',
                DB::raw("CONCAT(s.fldLastName, ', ', s.fldFirstName) as name"),
                's.fldSchool as school',
                't.fldUserId as cashierId',
                't.fldItemId as itemId',
                'm.fldDescription as description',
                't.fldPrice as price',
                't.fldCreatedDate as createdAt',
            ]);

        if ($userId !== '0') {
            $query->where('t.fldUserId', $userId);
        }

        $transactions = $query->get();

        // Group by student and filter to those with 2+ transactions
        $grouped = $transactions->groupBy('cloudId');
        $seconds = collect();

        foreach ($grouped as $studentTransactions) {
            if ($studentTransactions->count() >= 2) {
                foreach ($studentTransactions as $tx) {
                    $seconds->push([
                        'id' => $tx->id,
                        'studentId' => $tx->studentId,
                        'name' => $tx->name,
                        'school' => $tx->school ?? '',
                        'cashierId' => $tx->cashierId,
                        'itemId' => $tx->itemId,
                        'description' => $tx->description ?? "Item {$tx->itemId}",
                        'price' => (float) $tx->price,
                        'time' => Carbon::parse($tx->createdAt)->format('g:i A'),
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $seconds->values(),
            'meta' => [
                'reportType' => 's',
                'reportName' => 'Student Seconds',
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'date' => $date,
                'totalRecords' => $seconds->count(),
                'includeMilk' => $includeMilk,
            ],
        ]);
    }

    /**
     * Cash & Check Detail Report
     * Includes cash till tracking (start/end amounts) and payment breakdowns
     */
    private function cashCheckDetail(string $mealType, int $lineNum, string $date, string $userId, ?int $currentStationId, array $sessionToStation): JsonResponse
    {
        // Get line log for cash till amounts
        $lineLog = DB::table('ww_pos_log_lines')
            ->where('fldLineDate', $date)
            ->where('fldMealType', $mealType)
            ->where('fldLineNum', $lineNum)
            ->select([
                'fldId as line_id',
                'fldStartCashAmount as start_cash_amount',
                'fldEndCashAmount as end_cash_amount',
                'fldCloseDate as close_date',
            ])
            ->first();

        $query = DB::table('ww_pos_payments as p')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 'p.fldStudentId')
            ->leftJoin('ww_family as f', 'f.fldFamPermId', '=', 's.fldFamPermId')
            ->where('p.fldLineDate', $date)
            ->where('p.fldMealType', $mealType)
            ->where('p.fldLineNum', $lineNum)
            ->select([
                'p.fldId as id',
                'p.fldSyncKey as syncKey',
                's.fldLcsId as studentId',
                'f.fldFamilyId as familyId',
                DB::raw("CONCAT(s.fldLastName, ', ', s.fldFirstName) as name"),
                'p.fldUserId as cashierId',
                'p.fldAmount as amount',
                'p.fldMemo as memo',
                'p.fldIsCheck as isCheck',
                'p.fldCloudPaymentId as cloudPaymentId',
            ]);

        if ($userId !== '0') {
            $query->where('p.fldUserId', $userId);
        }

        $query->orderByRaw('p.fldIsCheck ASC, s.fldLastName ASC, s.fldFirstName ASC, p.fldCreatedDate ASC');

        $payments = $query->get();

        // Separate by type and calculate totals
        $cash = [];
        $checks = [];
        $credit = [];

        // Totals breakdown
        $totalCash = 0;
        $totalCashToAccounts = 0;
        $totalCashToAnonymous = 0;
        $totalCheck = 0;
        $totalCheckToAccounts = 0;
        $totalCheckToAnonymous = 0;
        $totalCredit = 0;
        $totalCreditToAccounts = 0;
        $totalCreditToAnonymous = 0;

        foreach ($payments as $p) {
            $stationId = null;
            $isThisStation = false;

            if ($p->syncKey) {
                $parts = explode('-', $p->syncKey);
                if (count($parts) >= 2) {
                    $sessionId = (int) $parts[1];
                    $stationId = $sessionToStation[$sessionId] ?? null;
                    $isThisStation = ($stationId === $currentStationId);
                }
            }

            $isAnonymous = ($p->studentId == 999999999);
            $amount = (float) $p->amount;

            $row = [
                'id' => $p->id,
                'studentId' => $isAnonymous ? 'CASH' : ($p->studentId ?? 'CASH'),
                'familyId' => $p->familyId ?? '',
                'name' => $isAnonymous ? 'Cash Customer' : ($p->name ?? 'Cash Customer'),
                'cashierId' => $p->cashierId,
                'amount' => $amount,
                'memo' => $p->memo ?? '',
                'stationId' => $stationId,
                'isThisStation' => $isThisStation,
                'isAnonymous' => $isAnonymous,
            ];

            // Credit card (has cloud payment ID)
            if ($p->cloudPaymentId) {
                $credit[] = $row;
                $totalCredit += $amount;
                if ($isAnonymous) {
                    $totalCreditToAnonymous += $amount;
                } else {
                    $totalCreditToAccounts += $amount;
                }
            }
            // Check
            elseif ($p->isCheck) {
                $checks[] = $row;
                $totalCheck += $amount;
                if ($isAnonymous) {
                    $totalCheckToAnonymous += $amount;
                } else {
                    $totalCheckToAccounts += $amount;
                }
            }
            // Cash
            else {
                $cash[] = $row;
                $totalCash += $amount;
                if ($isAnonymous) {
                    $totalCashToAnonymous += $amount;
                } else {
                    $totalCashToAccounts += $amount;
                }
            }
        }

        $grandTotal = $totalCash + $totalCheck + $totalCredit;

        // Parse cash till amounts (stored as JSON)
        $startCashAmount = null;
        $endCashAmount = null;
        if ($lineLog) {
            if ($lineLog->start_cash_amount) {
                $decoded = json_decode($lineLog->start_cash_amount, true);
                $startCashAmount = is_array($decoded) ? $decoded : $lineLog->start_cash_amount;
            }
            if ($lineLog->end_cash_amount) {
                $decoded = json_decode($lineLog->end_cash_amount, true);
                $endCashAmount = is_array($decoded) ? $decoded : $lineLog->end_cash_amount;
            }
        }

        return response()->json([
            'success' => true,
            'cash' => $cash,
            'checks' => $checks,
            'credit' => $credit,
            'totals' => [
                'totalCash' => $totalCash,
                'totalCashToAccounts' => $totalCashToAccounts,
                'totalCashToAnonymous' => $totalCashToAnonymous,
                'totalCheck' => $totalCheck,
                'totalCheckToAccounts' => $totalCheckToAccounts,
                'totalCheckToAnonymous' => $totalCheckToAnonymous,
                'totalCredit' => $totalCredit,
                'totalCreditToAccounts' => $totalCreditToAccounts,
                'totalCreditToAnonymous' => $totalCreditToAnonymous,
                'grandTotal' => $grandTotal,
            ],
            'cashTill' => [
                'lineId' => $lineLog ? $lineLog->line_id : null,
                'startCashAmount' => $startCashAmount,
                'endCashAmount' => $endCashAmount,
                'closeDate' => $lineLog ? $lineLog->close_date : null,
            ],
            'meta' => [
                'reportType' => 'c',
                'reportName' => 'Cash & Check Detail',
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'date' => $date,
                'currentStationId' => $currentStationId,
            ],
        ]);
    }

    /**
     * POST /pos/reports/{mealType}/{lineNum}/cash-till
     *
     * Set the start or end cash till amount for a line.
     */
    public function setCashTillAmount(Request $request, string $mealType, $lineNum): JsonResponse
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
                'date' => 'required|date_format:Y-m-d',
                'isStartCash' => 'required|boolean',
                'data' => 'required',
            ]);

            $date = $request->input('date');
            $isStartCash = $request->input('isStartCash');
            $data = $request->input('data');

            $columnToUpdate = $isStartCash ? 'fldStartCashAmount' : 'fldEndCashAmount';

            $updated = DB::table('ww_pos_log_lines')
                ->where('fldMealType', $mealType)
                ->where('fldLineNum', $lineNum)
                ->where('fldLineDate', $date)
                ->update([
                    $columnToUpdate => json_encode($data),
                ]);

            if ($updated === 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Line log not found for the specified date',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => ($isStartCash ? 'Start' : 'End') . ' cash amount updated',
            ]);

        } catch (\Exception $e) {
            \Log::error('Set cash till error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deleted Transactions Report
     */
    private function deletedTransactions(string $mealType, int $lineNum, string $date): JsonResponse
    {
        // Deleted transactions
        $transactions = DB::table('ww_pos_transactions_delete_log as d')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 'd.fldStudentId')
            ->leftJoin('ww_menuitem as m', 'm.fldItemId', '=', 'd.fldItemId')
            ->where('d.fldLineDate', $date)
            ->where('d.fldMealType', $mealType)
            ->where('d.fldLineNum', $lineNum)
            ->select([
                'd.fldId as id',
                's.fldLcsId as studentId',
                'm.fldDescription as description',
                'd.fldPrice as price',
                'd.fldDeletingUserId as deletedBy',
                'd.fldDeletedDate as deletedAt',
            ])
            ->orderBy('d.fldDeletedDate', 'asc')
            ->get();

        $txData = $transactions->map(function ($tx) {
            return [
                'id' => $tx->id,
                'studentId' => $tx->studentId ?? 'CASH',
                'description' => $tx->description ?? 'Unknown Item',
                'price' => (float) $tx->price,
                'deletedBy' => $tx->deletedBy,
                'deletedAt' => $tx->deletedAt ? Carbon::parse($tx->deletedAt)->format('g:i A') : '',
            ];
        });

        // Deleted payments
        $payments = DB::table('ww_pos_payments_delete_log as d')
            ->leftJoin('ww_student as s', 's.fldCloudId', '=', 'd.fldStudentId')
            ->where('d.fldLineDate', $date)
            ->where('d.fldMealType', $mealType)
            ->where('d.fldLineNum', $lineNum)
            ->select([
                'd.fldId as id',
                's.fldLcsId as studentId',
                'd.fldAmount as amount',
                'd.fldMemo as memo',
                'd.fldDeletingUserId as deletedBy',
                'd.fldDeletedDate as deletedAt',
            ])
            ->orderBy('d.fldDeletedDate', 'asc')
            ->get();

        $pmtData = $payments->map(function ($p) {
            return [
                'id' => $p->id,
                'studentId' => $p->studentId ?? 'CASH',
                'amount' => (float) $p->amount,
                'memo' => $p->memo ?? '',
                'deletedBy' => $p->deletedBy,
                'deletedAt' => $p->deletedAt ? Carbon::parse($p->deletedAt)->format('g:i A') : '',
            ];
        });

        return response()->json([
            'success' => true,
            'transactions' => $txData,
            'payments' => $pmtData,
            'meta' => [
                'reportType' => 'd',
                'reportName' => 'Deleted Transactions',
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'date' => $date,
            ],
        ]);
    }
}
