<?php

namespace App\Http\Controllers;

use App\Models\StationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * POS Student Controller
 * ============================================================================
 *
 * Handles student data download for POS lines.
 * Filters students by line's allowed schools and grades.
 * Grade 99 = adults, always allowed on any line.
 *
 * Related tables:
 *   - ww_student: Main student records
 *   - ww_family: Family/balance info (join on fldFamPermId)
 *   - ww_student_status: P/R/F status (join on fldStudentId, not fldStatusId)
 *   - ww_student_allergies: Allergy alerts (multiple per student)
 *   - ww_student_linedata: Per-student line settings (JSON in fldData)
 *
 * ============================================================================
 */
class POSStudentController extends Controller
{
    /**
     * GET /api/pos/lines/{mealType}/{lineNum}/students
     *
     * Download student roster for a specific line.
     * Filters by line's school list and grade list from ww_line.
     * Always includes grade 99 (adults).
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

        // Get line configuration (school list, grade list)
        $line = DB::table('ww_line')
            ->where('fldMealType', $mealType)
            ->where('fldLineNum', $lineNum)
            ->first();

        if (!$line) {
            return response()->json([
                'success' => false,
                'error' => "Line {$lineCode} not found",
            ], 404);
        }

        // Parse school and grade lists
        $lineData = (array) $line;
        $schoolList = $this->parseList($lineData['fldSchoolList'] ?? null);
        $gradeList = $this->parseList($lineData['fldGradeList'] ?? null);

        // Get students filtered by line's schools/grades
        $students = $this->getStudentsForLine($mealType, $lineNum, $schoolList, $gradeList);

        return response()->json([
            'success' => true,
            'students' => $students,
            'count' => count($students),
            'line' => [
                'mealType' => $mealType,
                'lineNum' => $lineNum,
                'lineCode' => $lineCode,
            ],
        ]);
    }

    /**
     * Check if a date of birth matches today's month and day
     */
    private function isBirthdayToday($dob): bool
    {
        if (empty($dob)) {
            return false;
        }

        try {
            $birthDate = new \DateTime($dob);
            $today = new \DateTime();

            return $birthDate->format('m-d') === $today->format('m-d');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Parse a list field (could be JSON array or comma-separated)
     */
    private function parseList($value): array
    {
        if (empty($value)) {
            return [];
        }

        // Try JSON decode first
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to comma-separated
        return array_map('trim', explode(',', $value));
    }

    /**
     * Get students filtered by school and grade lists
     * Always includes grade 99 (adults)
     */
    private function getStudentsForLine(string $mealType, int $lineNum, array $schoolList, array $gradeList): array
    {
        // Base student query with family join
        // fldEnrolled = 1 (enrolled), fldDeleted = 0 or NULL (not deleted)
        $query = DB::table('ww_student as s')
            ->leftJoin('ww_family as f', 'f.fldFamPermId', '=', 's.fldFamPermId')
            ->leftJoin('ww_student_status as ss', 'ss.fldStatusId', '=', 's.fldStatusId')
            ->where('s.fldEnrolled', 1)
            ->whereRaw('COALESCE(s.fldDeleted, 0) = 0');

        // Build school/grade filter
        // Grade 99 (adults) are always included regardless of school/grade restrictions
        // If both schoolList and gradeList are empty, return ALL students
        //
        // NOTE: School codes may have leading zeros in ww_student (e.g., "002")
        // but not in ww_line config (e.g., "2"). Compare as integers to handle this.
        $hasSchoolFilter = !empty($schoolList);
        $hasGradeFilter = !empty($gradeList);

        if ($hasSchoolFilter || $hasGradeFilter) {
            // Apply filters: grade 99 always included, OR match school/grade
            $query->where(function ($q) use ($schoolList, $gradeList, $hasSchoolFilter, $hasGradeFilter) {
                // Always include grade 99 (adults)
                $q->where('s.fldGrade', 99);

                // OR match school AND grade filters (both must match if both specified)
                $q->orWhere(function ($subQ) use ($schoolList, $gradeList, $hasSchoolFilter, $hasGradeFilter) {
                    if ($hasSchoolFilter) {
                        // Cast to integer for comparison to handle leading zeros
                        // e.g., "002" vs "2" should match
                        $schoolInts = array_map('intval', $schoolList);
                        $subQ->whereRaw('CAST(s.fldSchool AS UNSIGNED) IN (' . implode(',', $schoolInts) . ')');
                    }
                    if ($hasGradeFilter) {
                        // Grades can also be strings, cast to int
                        $gradeInts = array_map('intval', $gradeList);
                        $subQ->whereRaw('CAST(s.fldGrade AS SIGNED) IN (' . implode(',', $gradeInts) . ')');
                    }
                });
            });
        }
        // If no filters, all enrolled non-deleted students are returned (including grade 99)

        $students = $query
            ->select([
                's.fldCloudId as cloudId',
                's.fldLcsId as studentId',
                's.fldLunchId as lunchId',
                's.fldReferenceId as referenceId',
                's.fldFamPermId as familyId',
                's.fldFirstName as firstName',
                's.fldLastName as lastName',
                's.fldGrade as grade',
                's.fldSchool as schoolCode',
                's.fldHomeroom as homeroom',
                's.fldDOB as dob',
                'f.fldBalance as balance',
                'ss.fldStatus as studentType',
            ])
            ->orderBy('s.fldLastName')
            ->orderBy('s.fldFirstName')
            ->get();

        if ($students->isEmpty()) {
            return [];
        }

        // Get all student IDs and family IDs for batch loading related data
        $studentIds = $students->pluck('studentId')->filter()->toArray();
        $cloudIds = $students->pluck('cloudId')->filter()->toArray();
        $familyIds = $students->pluck('familyId')->filter()->unique()->toArray();

        // Batch load allergies for all students
        $allergiesMap = $this->getAllergiesForStudents($studentIds);

        // Batch load linedata for all students (uses cloudId, not studentId/lcsId)
        $linedataMap = $this->getLinedataForStudents($cloudIds, $mealType, $lineNum);

        // Batch load notes for all students (includes family-level notes)
        // Note: ww_note.fldStudentId matches ww_student.fldCloudId
        $notesMap = $this->getNotesForStudents($cloudIds, $familyIds);

        // Format each student with their related data
        return $students->map(function ($student) use ($allergiesMap, $linedataMap, $notesMap) {
            return $this->formatStudent($student, $allergiesMap, $linedataMap, $notesMap);
        })->toArray();
    }

    /**
     * Batch load allergies for multiple students
     * Returns map of studentId => array of allergies
     */
    private function getAllergiesForStudents(array $studentIds): array
    {
        if (empty($studentIds)) {
            return [];
        }

        $allergies = DB::table('ww_student_allergies')
            ->whereIn('fldStudentId', $studentIds)
            ->get();

        $map = [];
        foreach ($allergies as $allergy) {
            $data = (array) $allergy;
            $sid = $data['fldStudentId'] ?? null;
            if ($sid === null) continue;

            if (!isset($map[$sid])) {
                $map[$sid] = [];
            }

            $map[$sid][] = [
                'text' => $data['fldText'] ?? '',
                'mustAcknowledge' => (bool) ($data['fldMustAcknowledge'] ?? false),
                'allergens' => $this->parseAllergens($data['fldAllergens'] ?? null),
            ];
        }

        return $map;
    }

    /**
     * Parse allergens field (could be JSON or comma-separated)
     */
    private function parseAllergens($value): array
    {
        if (empty($value)) {
            return [];
        }

        // Try JSON decode first
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to comma-separated
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Batch load notes for students
     * Returns map with 'student' => [studentId => notes[]] and 'family' => [familyId => notes[]]
     */
    private function getNotesForStudents(array $studentIds, array $familyIds): array
    {
        $map = [
            'student' => [],
            'family' => [],
        ];

        if (empty($studentIds) && empty($familyIds)) {
            return $map;
        }

        // Get all notes for these students and families in one query
        $notes = DB::table('ww_note')
            ->where(function ($q) use ($studentIds, $familyIds) {
                if (!empty($studentIds)) {
                    $q->whereIn('fldStudentId', $studentIds);
                }
                if (!empty($familyIds)) {
                    $q->orWhereIn('fldFamPermId', $familyIds);
                }
            })
            ->whereRaw('COALESCE(fldDeleted, 0) = 0')
            ->get();

        foreach ($notes as $note) {
            $data = (array) $note;
            $noteText = $data['fldNote'] ?? '';

            if (empty($noteText)) {
                continue;
            }

            // Split by newlines - notes can have multiple lines in one field
            $noteLines = preg_split('/\r?\n/', $noteText);
            $noteLines = array_filter(array_map('trim', $noteLines));

            if (empty($noteLines)) {
                continue;
            }

            $sid = $data['fldStudentId'] ?? null;
            $fid = $data['fldFamPermId'] ?? null;

            // If note has studentId, it's a student note (even if familyId also set)
            // Only treat as family note if no studentId
            if ($sid !== null && $sid > 0) {
                if (!isset($map['student'][$sid])) {
                    $map['student'][$sid] = [];
                }
                foreach ($noteLines as $line) {
                    $map['student'][$sid][] = $line;
                }
            } elseif ($fid !== null && $fid > 0) {
                if (!isset($map['family'][$fid])) {
                    $map['family'][$fid] = [];
                }
                foreach ($noteLines as $line) {
                    $map['family'][$fid][] = $line;
                }
            }
        }

        return $map;
    }

    /**
     * Batch load linedata for multiple students
     * Returns map of cloudId => linedata object
     * Note: ww_student.fldCloudId joins to ww_student_linedata.fldStudentId
     */
    private function getLinedataForStudents(array $cloudIds, string $mealType, int $lineNum): array
    {
        if (empty($cloudIds)) {
            return [];
        }

        // ww_student_linedata.fldStudentId contains the cloudId value
        $linedata = DB::table('ww_student_linedata')
            ->whereIn('fldStudentId', $cloudIds)
            ->whereRaw('COALESCE(fldDeleted, 0) = 0')
            ->get();

        $map = [];
        foreach ($linedata as $ld) {
            $data = (array) $ld;
            // fldStudentId in linedata table = cloudId from student table
            $cloudId = $data['fldStudentId'] ?? null;
            if ($cloudId === null) continue;

            // Parse the JSON data field
            $jsonData = json_decode($data['fldData'] ?? '{}', true) ?? [];

            // Look for settings specific to this line
            // Could be keyed by "L1", "B2", etc. or nested structure
            $lineKey = $mealType . $lineNum;
            $lineSettings = $jsonData[$lineKey] ?? $jsonData['lines'][$lineKey] ?? $jsonData;

            $map[$cloudId] = $lineSettings;
        }

        return $map;
    }

    /**
     * Format student for API response
     *
     * Student Type Logic:
     *   - Grade 99 (adults/staff): Always use "S" (Staff) UNLESS they have a
     *     non-standard status. If status is null, missing, P, R, or F → use "S".
     *   - Regular students: Use status from ww_student_status (P/R/F), default to "P".
     *
     * Type codes: P=Paid, R=Reduced, F=Free, S=Staff
     */
    private function formatStudent($student, array $allergiesMap, array $linedataMap, array $notesMap): array
    {
        $data = (array) $student;
        $studentId = $data['studentId'] ?? null;
        $cloudId = $data['cloudId'] ?? null;
        $familyId = $data['familyId'] ?? null;

        // Get allergies for this student (empty array if none)
        $allergies = $allergiesMap[$studentId] ?? [];

        // Check if any allergy requires acknowledgment
        $hasAllergyAlert = false;
        foreach ($allergies as $allergy) {
            if ($allergy['mustAcknowledge'] ?? false) {
                $hasAllergyAlert = true;
                break;
            }
        }

        // Get linedata for this student (null if none) - keyed by cloudId
        $lineSettings = $linedataMap[$cloudId] ?? null;

        // Get notes for this student (student-level + family-level)
        // Note: ww_note.fldStudentId matches ww_student.fldCloudId
        $studentNotes = $notesMap['student'][$cloudId] ?? [];
        $familyNotes = $notesMap['family'][$familyId] ?? [];
        $notes = array_merge($studentNotes, $familyNotes);

        // Determine student type
        // Grade 99 = adults: use "S" (Staff) if status is missing/null or is P/R/F
        // Regular students: use status from record, default to "P" (Paid)
        $grade = $data['grade'] ?? null;
        $statusFromDb = $data['studentType'] ?? null;

        if ($grade == 99) {
            // Adults: S unless they have a non-standard status (not P/R/F)
            if (empty($statusFromDb) || in_array(strtoupper($statusFromDb), ['P', 'R', 'F'])) {
                $studentType = 'S';
            } else {
                $studentType = $statusFromDb;
            }
        } else {
            // Regular students: P/R/F, default to P
            $studentType = $statusFromDb ?? 'P';
        }

        return [
            'id' => $data['cloudId'] ?? $data['studentId'] ?? '',
            'studentId' => $data['studentId'] ?? '',
            'lunchId' => $data['lunchId'] ?? '',
            'referenceId' => $data['referenceId'] ?? '',
            'familyId' => $data['familyId'] ?? '',
            'firstName' => $data['firstName'] ?? '',
            'lastName' => $data['lastName'] ?? '',
            'grade' => $data['grade'] ?? '',
            'schoolCode' => $data['schoolCode'] ?? '',
            'homeroom' => $data['homeroom'] ?? '',
            'birthday' => $this->isBirthdayToday($data['dob'] ?? null),
            'balance' => (float) ($data['balance'] ?? 0),
            'studentType' => $studentType, // P=Paid, R=Reduced, F=Free, S=Staff (adults)
            'allergies' => $allergies,
            'hasAllergyAlert' => $hasAllergyAlert,
            'notes' => $notes,
            'hasNotes' => !empty($notes),
            'lineSettings' => $lineSettings,
        ];
    }
}
