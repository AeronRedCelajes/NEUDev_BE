<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ClassroomController extends Controller
{
    /**
     * Get all classes.
     */
    public function index(Request $request)
    {
        $teacher = Auth::user();
    
        if (!$teacher || !$teacher instanceof Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        $archived = $request->query('archived', false);
        $query = Classroom::where('teacherID', $teacher->teacherID);
        
        if ($archived) {
            $query->where('activeClass', false);
        } else {
            $query->where('activeClass', true);
        }
    
        $classes = $query->with('students')->get();
    
        // Convert the cover image for each classroom
        foreach ($classes as $class) {
            $class->classCoverImage = $class->classCoverImage ? asset('storage/' . $class->classCoverImage) : null;
        }
    
        return response()->json($classes);
    }
    
    /**
     * Create a class (Only for Teachers).
     */
    public function store(Request $request)
    {
        Log::info('Received Request Data:', $request->all());
    
        $request->validate([
            'className'       => 'required|string|max:255',
            'classSection'    => 'nullable|string|max:255',
            // If handling image uploads here, adjust validation accordingly.
            'activeClass'     => 'nullable|boolean',
        ]);
    
        $teacher = Auth::user();
        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        // For simplicity, if a cover image is provided as a relative path or file,
        // you would handle it similarly to the update method.
        $classCoverImage = $request->classCoverImage ?? null;
    
        $classroom = Classroom::create([
            'className'       => $request->className,
            'classSection'    => $request->classSection,
            'teacherID'       => $teacher->teacherID,
            'classCoverImage' => $classCoverImage,
            'activeClass'     => $request->has('activeClass') ? $request->activeClass : true,
        ]);
    
        // Convert cover image to full URL if available.
        $classroom->classCoverImage = $classroom->classCoverImage ? asset('storage/' . $classroom->classCoverImage) : null;
    
        return response()->json($classroom, 201);
    }    

    /**
     * Get a specific class.
     */
    public function show($id)
    {
        $classroom = Classroom::with('teacher', 'students')->find($id);
        if (!$classroom) {
            return response()->json(['message' => 'Class not found'], 404);
        }
    
        // Convert the cover image to a full URL.
        $classroom->classCoverImage = $classroom->classCoverImage ? asset('storage/' . $classroom->classCoverImage) : null;
    
        return response()->json([
            'classID'         => $classroom->classID,
            'className'       => $classroom->className,
            'classSection'    => $classroom->classSection, 
            'classCoverImage' => $classroom->classCoverImage,
            'activeClass'     => $classroom->activeClass,
            'teacher'         => [
                'teacherID'   => $classroom->teacher->teacherID,
                'teacherName' => "{$classroom->teacher->firstname} {$classroom->teacher->lastname}",
            ],
            'students' => $classroom->students->map(function ($student) {
                return [
                    'studentID' => $student->studentID,
                    'firstname' => $student->firstname,
                    'lastname'  => $student->lastname,
                    'email'     => $student->email,
                ];
            }),
        ]);
    }

    /**
     * Delete a class (Only for Teachers).
     */
    public function destroy($id)
    {
        $teacher = Auth::user();
        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $classroom = Classroom::where('classID', $id)
            ->where('teacherID', $teacher->teacherID)
            ->first();

        if (!$classroom) {
            return response()->json(['message' => 'Class not found or you are not authorized to delete this class'], 404);
        }

        $classroom->delete();
        return response()->json(['message' => 'Class deleted successfully']);
    }

    /**
     * Update a class (Only for Teachers).
     */
    public function update(Request $request, $id)
    {
        $teacher = Auth::user();
        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        $classroom = Classroom::where('classID', $id)
            ->where('teacherID', $teacher->teacherID)
            ->first();
    
        if (!$classroom) {
            return response()->json(['message' => 'Class not found or you are not authorized to update this class'], 404);
        }
    
        $request->validate([
            'className'       => 'required|string|max:255',
            'classSection'    => 'required|string|max:255',
            'classCoverImage' => 'nullable|image|mimes:jpg,jpeg,png,gif,svg|max:10048',
            'activeClass'     => 'nullable|boolean',
        ]);
    
        $classroom->className    = $request->className;
        $classroom->classSection = $request->classSection;
    
        if ($request->hasFile('classCoverImage')) {
            $path = $request->file('classCoverImage')->store('class_covers', 'public');
            // Store relative path; the conversion happens below.
            $classroom->classCoverImage = $path;
        } else {
            // If no new file, fallback to provided string or retain original.
            $classroom->classCoverImage = $request->classCoverImage ?? $classroom->getOriginal('classCoverImage');
        }
    
        if ($request->has('activeClass')) {
            $classroom->activeClass = $request->activeClass;
        }
    
        $classroom->save();
    
        // Convert to full URL before returning.
        $classroom->classCoverImage = $classroom->classCoverImage ? asset('storage/' . $classroom->classCoverImage) : null;
    
        return response()->json($classroom);
    }

    /**
     * Enroll a student in a class.
     */
    public function enrollStudent(Request $request, $classID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $classroom = Classroom::find($classID);
        if (!$classroom) {
            return response()->json(['message' => 'Class not found'], 404);
        }

        if ($classroom->students()->where('students.studentID', $student->studentID)->exists()) {
            return response()->json(['message' => 'Student is already enrolled in this class'], 409);
        }

        $classroom->students()->attach($student->studentID);
        return response()->json(['message' => 'Enrolled successfully']);
    }

    /**
     * Unenroll a student from a class.
     */
    public function unenrollStudent(Request $request, $classID, $studentID)
    {
        $teacher = Auth::user();
        if (!$teacher || !$teacher instanceof Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        $classroom = Classroom::find($classID);
        if (!$classroom) {
            return response()->json(['message' => 'Class not found'], 404);
        }
    
        if (!$classroom->students()->where('students.studentID', $studentID)->exists()) {
            return response()->json(['message' => 'Student is not enrolled in this class'], 409);
        }
    
        $classroom->students()->detach($studentID);
        return response()->json(['message' => 'Student unenrolled successfully']);
    }

    /**
     * Get only the classes a student is enrolled in.
     */
    public function getStudentClasses()
    {
        $student = Auth::user();
        if (!$student || !$student instanceof Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $classes = $student->classes()->with('teacher')->get();
        $formattedClasses = $classes->map(function ($class) {
            $data = $class->toArray();
            $data['teacherName'] = $class->teacher ? "{$class->teacher->firstname} {$class->teacher->lastname}" : 'Unknown Teacher';
            // Convert cover image to full URL:
            $data['classCoverImage'] = $class->classCoverImage ? asset('storage/' . $class->classCoverImage) : null;
            return $data;
        });

        return response()->json($formattedClasses);
    }

    /**
     * Get class info.
     */
    public function showClassInfo($id)
    {
        $classroom = Classroom::with('teacher')->find($id);
        if (!$classroom) {
            return response()->json(['message' => 'Class not found'], 404);
        }
        
        // Convert cover image to full URL.
        $classroom->classCoverImage = $classroom->classCoverImage ? asset('storage/' . $classroom->classCoverImage) : null;

        return response()->json([
            'classID'         => $classroom->classID,
            'className'       => $classroom->className,
            'classSection'    => $classroom->classSection,
            'classCoverImage' => $classroom->classCoverImage,
            'activeClass'     => $classroom->activeClass,
            'teacher'         => [
                'teacherID'   => $classroom->teacher->teacherID,
                'teacherName' => $classroom->teacher->firstname . ' ' . $classroom->teacher->lastname,
            ],
        ]);
    }

    /**
     * Get students of a class.
     */
    public function getClassStudents($classID)
    {
        $classroom = Classroom::with('students')->find($classID);
        if (!$classroom) {
            return response()->json(['message' => 'Class not found'], 404);
        }
    
        $students = $classroom->students->map(function ($student) {
            return [
                'studentID'     => $student->studentID,
                'firstname'     => $student->firstname,
                'lastname'      => $student->lastname,
                'studentNumber' => $student->student_num,
                'profileImage'  => $student->profileImage,
                'averageScore'  => rand(70, 100) // Replace with actual computation if needed.
            ];
        });
    
        return response()->json($students);
    }


    ///////////////////////////////////////////////////
    // NEW FUNCTION: Overall Student Score Across Activities
    ///////////////////////////////////////////////////
    
    public function getClassStudentsWithOverallScores($classID)
    {
        // 1) Verify teacher
        $teacher = Auth::user();
        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // 2) Get the class from DB, ensure the teacher owns it or has permission
        $class = \DB::table('classes')
            ->where('classID', $classID)
            ->where('teacherID', $teacher->teacherID)
            ->first();

        if (!$class) {
            return response()->json(['error' => 'Class not found or unauthorized'], 404);
        }

        // 3) Get all the students for this class
        $students = \DB::table('students')
            ->join('class_student', 'students.studentID', '=', 'class_student.studentID')
            ->where('class_student.classID', $classID)
            ->select('students.studentID', 'students.firstname', 'students.lastname',
                    'students.student_num as studentNumber', 'students.profileImage')
            ->get();

        // 4) Get all activities in this class
        $activities = \App\Models\Activity::where('classID', $classID)->get();

        // Compute the total possible points for the class
        $totalMaxPoints = $activities->sum('maxPoints');

        // 5) For each student, sum up finalScore from pivot "activity_student" for these activities
        $results = [];
        foreach ($students as $stud) {
            // Sum the finalScore from pivot
            $sumOfScores = 0;

            foreach ($activities as $act) {
                // The pivot row for this student & activity
                $pivot = \DB::table('activity_student')
                    ->where('actID', $act->actID)
                    ->where('studentID', $stud->studentID)
                    ->first();
                if ($pivot && $pivot->finalScore) {
                    $sumOfScores += $pivot->finalScore;
                }
            }

            // Build the array, storing the fraction "sumOfScores / totalMaxPoints"
            $results[] = [
                'studentID'     => $stud->studentID,
                'firstname'     => $stud->firstname,
                'lastname'      => $stud->lastname,
                'studentNumber' => $stud->studentNumber,
                'profileImage'  => $stud->profileImage ? asset('storage/' . $stud->profileImage) : null,

                // The fraction or numeric fields – your choice
                'sumOfScores'      => $sumOfScores,
                'sumOfMaxPoints'   => $totalMaxPoints,

                // Or you can store a preformatted string e.g. "21/60".
                // But usually best to do the string in the frontend, for sorting reasons
                // 'averageScoreString' => "$sumOfScores/$totalMaxPoints"
            ];
        }

        return response()->json($results);
    }
    
}