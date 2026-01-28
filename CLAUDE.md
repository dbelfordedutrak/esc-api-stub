# ESC Cafeteria POS - Laravel API Stub

## Project Overview
Laravel API stub for ESC Cafeteria POS system. Provides mock endpoints for development and testing of the Vue frontend.

## Running the API
```bash
# From WSL
cd /mnt/c/Users/SlowJam/Projects/api-stub
php artisan serve --host=0.0.0.0 --port=8000
```

Access at: `http://localhost:8000`

## Key Controllers

### POSController (`app/Http/Controllers/POSController.php`)
Main POS operations:
- `getLines()` - GET `/api/pos/lines` - Returns available lines with status
- `openLine()` - POST `/api/pos/lines/{mealType}/{lineNum}/open` - Opens a line for the day
- `closeLine()` - POST `/api/pos/lines/{mealType}/{lineNum}/close` - Closes a line

### POSStudentController (`app/Http/Controllers/POSStudentController.php`)
Student data:
- `getLineStudents()` - GET `/api/pos/lines/{mealType}/{lineNum}/students`
- Returns students with: id, studentId, lunchId, referenceId, firstName, lastName, balance, studentType, lineSettings, etc.

### POSMenuController (`app/Http/Controllers/POSMenuController.php`)
Menu items:
- `getLineMenu()` - GET `/api/pos/lines/{mealType}/{lineNum}/menu`
- Returns menu items with: itemId, name, shortName, itemType, priceP/R/F, tabIndex, row, col, color, etc.

## API Routes (`routes/api.php`)

```
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/pos/lines
POST   /api/pos/lines/{mealType}/{lineNum}/open
POST   /api/pos/lines/{mealType}/{lineNum}/close
GET    /api/pos/lines/{mealType}/{lineNum}/students
GET    /api/pos/lines/{mealType}/{lineNum}/menu
GET    /api/pos/lines/{mealType}/{lineNum}/settings
```

## Recent Changes (Jan 2025)

### POSController Fix
- `openLine()` - Fixed bug where `$user->fldUsername` was used instead of `$user->fldUserId`
- The `fldOpenUser` column expects an integer (user ID), not a string (username)
- Line ~410: Changed to `$user->fldUserId`

## Database Tables Used

- `ww_pos_linelog` - Line open/close tracking
- `ww_student` - Student roster
- `ww_student_linedata` - Per-student line permissions (JSON in fldData)
- `ww_menuitem_pos` - Menu item button configuration
- `ww_system` - System settings

## Student Data Format

The API returns students with these key fields:
```json
{
  "id": "cloudId or studentId",
  "studentId": "fldLcsId",
  "lunchId": "fldLunchId (always numeric)",
  "referenceId": "fldReferenceId (can be alphanumeric)",
  "firstName": "fldFirstName",
  "lastName": "fldLastName",
  "studentType": "F/R/P/E/S",
  "balance": 0.00,
  "lineSettings": { "L": { "lp": 1, "ap": 1, "mp": 1, "lt": 5 } }
}
```

Note: `studentId` and `referenceId` can be strings (alphanumeric), `lunchId` is always numeric.

## Line Settings (lineSettings JSON)

Per-student permissions stored in `ww_student_linedata.fldData`:
- `lp` - Line permission (0=blocked, 1=allowed, undefined=allowed)
- `ap` - A la carte permission
- `mp` - Milk permission
- `lt` - Daily limit
- `di` - Default item ID

Frontend interprets missing/undefined as ALLOWED (not blocked).
