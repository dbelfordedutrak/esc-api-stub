<?php

namespace App\Http\Controllers;

use App\Models\StationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * POS Menu Controller
 * ============================================================================
 *
 * Handles menu item retrieval for POS lines.
 * Menu items include button grid configuration (tab, row, col, color).
 *
 * ============================================================================
 */
class POSMenuController extends Controller
{
    /**
     * GET /api/pos/lines/{mealType}/{lineNum}/menu
     *
     * Get menu items for a specific line.
     * Returns items with button grid positions for the POS UI.
     */
    public function index(Request $request, string $mealType, int $lineNum): JsonResponse
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'error' => 'No authorization token provided',
            ], 401);
        }

        $session = StationSession::findByToken($token);

        if (!$session) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid or expired session',
            ], 401);
        }

        // Check if user has access to this line
        $lineCode = $mealType . $lineNum;
        if (!$session->hasAbility('line:*') && !$session->hasAbility('line:' . $lineCode)) {
            return response()->json([
                'success' => false,
                'error' => 'Access denied to this line',
            ], 403);
        }

        // Update last activity
        $session->updateLastActivity();

        // Get menu items with button grid info
        // Button positions may be in ww_menuitem_buttons or ww_menuitem_line_buttons
        $items = $this->getMenuItemsForLine($mealType, $lineNum);

        return response()->json([
            'success' => true,
            'items' => $items,
            'count' => count($items),
            'line' => [
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'lineCode' => $lineCode,
            ],
        ]);
    }

    /**
     * Get menu items for a specific line
     * Joins menu items with button positions from ww_menuitem_pos
     */
    private function getMenuItemsForLine(string $mealType, int $lineNum): array
    {
        // ww_menuitem_pos maps items to lines with grid position and color
        // Join on fldItemId (not fldId)
        $items = DB::table('ww_menuitem_pos as p')
            ->join('ww_menuitem as m', 'p.fldItemId', '=', 'm.fldItemId')
            ->where('p.fldMealType', $mealType)
            ->where('p.fldLineNum', $lineNum)
            ->whereRaw('COALESCE(m.fldDeleted, 0) = 0')
            ->get();

        if ($items->isEmpty()) {
            // Try without line filter (global menu for meal type)
            $items = DB::table('ww_menuitem_pos as p')
                ->join('ww_menuitem as m', 'p.fldItemId', '=', 'm.fldItemId')
                ->where('p.fldMealType', $mealType)
                ->whereRaw('COALESCE(m.fldDeleted, 0) = 0')
                ->get();
        }

        if ($items->isEmpty()) {
            // Fallback: return all items without positions
            return DB::table('ww_menuitem')
                ->whereRaw('COALESCE(fldDeleted, 0) = 0')
                ->get()
                ->map(fn($item) => $this->formatMenuItemFromRaw($item))
                ->toArray();
        }

        return $items->map(fn($item) => $this->formatMenuItemWithPos($item))->toArray();
    }

    /**
     * Format menu item joined with ww_menuitem_pos
     */
    private function formatMenuItemWithPos($item): array
    {
        $data = (array) $item;

        // Menu item fields (from ww_menuitem)
        $priceP = (float) ($data['fldFullPrice'] ?? $data['fldPricePaid'] ?? 0);
        $name = $data['fldDescription'] ?? '';

        return [
            'id' => (int) ($data['fldId'] ?? $data['fldMenuItemId'] ?? 0),
            'itemId' => $data['fldItemId'] ?? '',
            'name' => $name,
            'shortName' => $data['fldShortDesc'] ?? $name,
            'itemType' => $data['fldItemType'] ?? 'A',
            'priceP' => $priceP,
            'priceR' => (float) ($data['fldReducedPrice'] ?? 0),
            'priceF' => (float) ($data['fldFreePrice'] ?? 0),
            'priceA' => (float) ($data['fldAdditionalPrice'] ?? $priceP),
            'priceCash' => (float) ($data['fldCashPrice'] ?? $priceP),
            'priceE' => (float) ($data['fldEmployeePrice'] ?? $priceP),
            'priceS' => (float) ($data['fldStaffPrice'] ?? $priceP),
            'priceG' => (float) ($data['fldGuestPrice'] ?? $priceP),
            'upcCode' => $data['fldUPC'] ?? '',
            // Position fields (from ww_menuitem_pos)
            'tabIndex' => (int) ($data['fldTabIndex'] ?? $data['fldTab'] ?? 0),
            'row' => (int) ($data['fldRow'] ?? 0),
            'col' => (int) ($data['fldCol'] ?? $data['fldColumn'] ?? 0),
            'color' => $data['fldColor'] ?? $data['fldBgColor'] ?? 'BLUE',
            'active' => 1,
        ];
    }

    /**
     * Format menu item from raw ww_menuitem row (no position data)
     */
    private function formatMenuItemFromRaw($item): array
    {
        // Convert to array for safe property access
        $data = (array) $item;

        $priceP = (float) ($data['fldFullPrice'] ?? $data['fldPricePaid'] ?? 0);
        $name = $data['fldDescription'] ?? '';

        return [
            'id' => (int) ($data['fldId'] ?? 0),
            'itemId' => $data['fldItemId'] ?? '',
            'name' => $name,
            'shortName' => $data['fldShortDesc'] ?? $name,
            'itemType' => $data['fldItemType'] ?? 'A',
            'priceP' => $priceP,
            'priceR' => (float) ($data['fldReducedPrice'] ?? 0),
            'priceF' => (float) ($data['fldFreePrice'] ?? 0),
            'priceA' => (float) ($data['fldAdditionalPrice'] ?? $priceP),
            'priceCash' => (float) ($data['fldCashPrice'] ?? $priceP),
            'priceE' => (float) ($data['fldEmployeePrice'] ?? $priceP),
            'priceS' => (float) ($data['fldStaffPrice'] ?? $priceP),
            'priceG' => (float) ($data['fldGuestPrice'] ?? $priceP),
            'upcCode' => $data['fldUPC'] ?? '',
            'tabIndex' => 0,
            'row' => 0,
            'col' => 0,
            'color' => 'BLUE',
            'active' => 1,
        ];
    }
}
