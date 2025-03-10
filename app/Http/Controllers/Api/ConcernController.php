<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Concern;
use Illuminate\Support\Facades\DB;

class ConcernController extends Controller
{
    /**
     * List all concerns for a specific class.
     */
    public function index($classID)
    {
        $concerns = Concern::where('classID', $classID)
            ->orderBy('created_at', 'desc')
            ->with(['student', 'teacher'])
            ->get();

        return response()->json($concerns);
    }

    /**
     * Show a single concern by its ID.
     */
    public function show($id)
    {
        $concern = Concern::with(['student', 'teacher'])->find($id);
        if (!$concern) {
            return response()->json(['message' => 'Concern not found'], 404);
        }
        return response()->json($concern);
    }

    /**
     * Create a new concern.
     * Only a student can post a concern.
     * The teacherID is derived from the class record.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Ensure only a student can post a concern
        if ($user instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Only students can post concerns'], 403);
        }

        $validatedData = $request->validate([
            'classID' => 'required|integer|exists:classes,classID',
            'concern' => 'required|string'
        ]);

        // Use the authenticated student's ID
        $validatedData['studentID'] = $user->studentID;

        // Get the teacherID from the classes table.
        $class = DB::table('classes')->where('classID', $validatedData['classID'])->first();
        if (!$class) {
            return response()->json(['message' => 'Class not found'], 404);
        }
        $validatedData['teacherID'] = $class->teacherID;

        // Ensure reply is null on creation.
        $validatedData['reply'] = null;

        $concern = Concern::create($validatedData);

        return response()->json([
            'message' => 'Concern posted successfully!',
            'concern' => $concern
        ], 201);
    }

    /**
     * Update a concern.
     * - If the request includes 'reply', then only the assigned teacher may update the reply.
     * - If the request includes 'concern', then only the student who posted it may update the text,
     *   and only if no reply has been provided yet.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $concern = Concern::find($id);
        if (!$concern) {
            return response()->json(['message' => 'Concern not found'], 404);
        }

        // Teacher updating the reply
        if ($request->has('reply')) {
            if (!($user instanceof \App\Models\Teacher)) {
                return response()->json(['message' => 'Only teachers can update replies'], 403);
            }
            if ($user->teacherID != $concern->teacherID) {
                return response()->json(['message' => 'Unauthorized: You are not assigned to this concern'], 403);
            }
            $request->validate([
                'reply' => 'required|string'
            ]);
            $concern->reply = $request->reply;
            $concern->save();
            return response()->json([
                'message' => 'Reply updated successfully',
                'concern' => $concern
            ]);
        }

        // Student updating their concern text (only if no reply exists)
        if ($request->has('concern')) {
            if (!($user instanceof \App\Models\Student)) {
                return response()->json(['message' => 'Only the student who posted the concern can update it'], 403);
            }
            if ($user->studentID != $concern->studentID) {
                return response()->json(['message' => 'Unauthorized: This is not your concern'], 403);
            }
            if ($concern->reply !== null) {
                return response()->json(['message' => 'Cannot update concern after a reply has been made'], 403);
            }
            $request->validate([
                'concern' => 'required|string'
            ]);
            $concern->concern = $request->concern;
            $concern->save();
            return response()->json([
                'message' => 'Concern updated successfully',
                'concern' => $concern
            ]);
        }

        return response()->json(['message' => 'No valid fields provided for update'], 400);
    }

    /**
     * Delete a concern.
     * - A student can delete their concern only if no reply has been made.
     * - A teacher can delete a concern if they are assigned to it.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $concern = Concern::find($id);
        if (!$concern) {
            return response()->json(['message' => 'Concern not found'], 404);
        }

        if ($user instanceof \App\Models\Student) {
            if ($user->studentID != $concern->studentID) {
                return response()->json(['message' => 'Unauthorized: This is not your concern'], 403);
            }
            // if ($concern->reply !== null) {
            //     return response()->json(['message' => 'Cannot delete concern after a reply has been made'], 403);
            // }
        } elseif ($user instanceof \App\Models\Teacher) {
            if ($user->teacherID != $concern->teacherID) {
                return response()->json(['message' => 'Unauthorized: You are not assigned to this concern'], 403);
            }
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $concern->delete();
        return response()->json(['message' => 'Concern deleted successfully']);
    }
}