<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Question;
use App\Models\TestCase;
use App\Models\ActivityQuestion;
use Illuminate\Support\Facades\DB;

class QuestionController extends Controller
{
    /**
     * Get all questions for a specific item type (with test cases & programming languages).
     * 
     * Optional query parameters:
     * - progLangID: to filter by programming language(s)
     * - scope: if set to 'personal', only return questions where teacherID matches the provided teacherID (e.g. via auth/session or request parameter);
     *          if set to 'global', only return questions with teacherID IS NULL.
     * - teacherID: the teacher's id when scope is 'personal'
     */
    public function getByItemType(Request $request, $itemTypeID)
    {
        $query = Question::where('itemTypeID', $itemTypeID)
            ->with(['testCases', 'programmingLanguages'])
            ->orderBy('updated_at', 'desc');

        // Filter by programming language if provided
        if ($request->has('progLangID')) {
            $query->whereHas('programmingLanguages', function ($q) use ($request) {
                $q->whereIn('progLangID', (array) $request->progLangID);
            });
        }

        // Optional filtering based on scope.
        if ($request->has('scope')) {
            $scope = $request->scope;
            if ($scope === 'personal') {
                // For personal questions, ensure teacherID is provided.
                if ($request->has('teacherID')) {
                    $query->where('teacherID', $request->teacherID);
                }
            } elseif ($scope === 'global') {
                $query->whereNull('teacherID');
            }
        }

        return response()->json($query->get(), 200);
    }

    /**
     * Create a new question with multiple programming languages and conditional test cases.
     */
    public function store(Request $request)
    {
        // Fetch the item type based on the provided itemTypeID
        $itemType = DB::table('item_types')->where('itemTypeID', $request->itemTypeID)->first();
        if (!$itemType) {
            return response()->json(['message' => 'Invalid item type.'], 400);
        }

        // Define base rules for all item types.
        $rules = [
            'itemTypeID'         => 'required|exists:item_types,itemTypeID',
            'teacherID'          => 'nullable|exists:teachers,teacherID', // Optional: if provided, this question is personal.
            'progLangIDs'        => 'required|array',
            'progLangIDs.*'      => 'exists:programming_languages,progLangID',
            'questionName'       => 'required|string|max:255',
            'questionDesc'       => 'required|string',
            'questionDifficulty' => 'required|in:Beginner,Intermediate,Advanced',
            'questionPoints'     => 'required|integer|min:1',
        ];

        // Enforce test case rules only for Console App.
        if ($itemType->itemTypeName === 'Console App') {
            $rules['testCases'] = 'required|array';
            $rules['testCases.*.inputData'] = 'nullable|string';
            $rules['testCases.*.expectedOutput'] = 'required|string';
            $rules['testCases.*.testCasePoints'] = 'required|integer|min:0';
        } else {
            // For other item types, test cases are optional.
            $rules['testCases'] = 'nullable|array';
        }

        $validatedData = $request->validate($rules);

        DB::beginTransaction();

        try {
            // Create the question.
            $question = Question::create([
                'itemTypeID'         => $validatedData['itemTypeID'],
                'teacherID'          => $request->teacherID ?? null, // If provided, this question is personal.
                'questionName'       => $validatedData['questionName'],
                'questionDesc'       => $validatedData['questionDesc'],
                'questionDifficulty' => $validatedData['questionDifficulty'],
                'questionPoints'     => $validatedData['questionPoints'],
            ]);

            // Attach programming languages.
            $question->programmingLanguages()->attach($validatedData['progLangIDs']);

            // Process test cases if provided.
            if (!empty($validatedData['testCases'])) {
                foreach ($validatedData['testCases'] as $testCase) {
                    TestCase::create([
                        'questionID'     => $question->questionID,
                        'inputData'      => $testCase['inputData'] ?? "",
                        'expectedOutput' => $testCase['expectedOutput'],
                        'testCasePoints' => $testCase['testCasePoints'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message'    => 'Question created successfully',
                'data'       => $question->load(['testCases', 'programmingLanguages']),
                'created_at' => $question->created_at->toDateTimeString(),
                'updated_at' => $question->updated_at->toDateTimeString(),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create question',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single question by ID (with test cases & programming languages).
     */
    public function show($questionID)
    {
        $question = Question::with(['testCases', 'programmingLanguages'])->find($questionID);

        if (!$question) {
            return response()->json(['message' => 'Question not found'], 404);
        }

        return response()->json([
            'question'   => $question,
            'created_at' => $question->created_at->toDateTimeString(),
            'updated_at' => $question->updated_at->toDateTimeString()
        ]);
    }

    /**
     * Update a question, including multiple programming languages and conditional test cases.
     */
    public function update(Request $request, $questionID)
    {
        $question = Question::find($questionID);

        if (!$question) {
            return response()->json(['message' => 'Question not found'], 404);
        }

        // Fetch the item type based on the provided itemTypeID.
        $itemType = DB::table('item_types')->where('itemTypeID', $request->itemTypeID)->first();
        if (!$itemType) {
            return response()->json(['message' => 'Invalid item type.'], 400);
        }

        // Define base rules.
        $rules = [
            'itemTypeID'         => 'required|exists:item_types,itemTypeID',
            // We typically do not change teacherID on update; you may choose to leave it out.
            'progLangIDs'        => 'required|array',
            'progLangIDs.*'      => 'exists:programming_languages,progLangID',
            'questionName'       => 'required|string|max:255',
            'questionDesc'       => 'required|string',
            'questionDifficulty' => 'required|in:Beginner,Intermediate,Advanced',
            'questionPoints'     => 'required|integer|min:1',
        ];

        // Enforce test case rules only for Console App.
        if ($itemType->itemTypeName === 'Console App') {
            $rules['testCases'] = 'required|array';
            $rules['testCases.*.inputData'] = 'nullable|string';
            $rules['testCases.*.expectedOutput'] = 'required|string';
            $rules['testCases.*.testCasePoints'] = 'required|integer|min:0';
        } else {
            $rules['testCases'] = 'nullable|array';
        }

        $validatedData = $request->validate($rules);

        DB::beginTransaction();

        try {
            // Update question details.
            $question->update([
                'itemTypeID'         => $validatedData['itemTypeID'],
                'questionName'       => $validatedData['questionName'],
                'questionDesc'       => $validatedData['questionDesc'],
                'questionDifficulty' => $validatedData['questionDifficulty'],
                'questionPoints'     => $validatedData['questionPoints'],
            ]);

            // Sync programming languages.
            $question->programmingLanguages()->sync($validatedData['progLangIDs']);

            // Update test cases: delete existing and add new ones if provided.
            if ($request->has('testCases')) {
                TestCase::where('questionID', $questionID)->delete();
                if (!empty($validatedData['testCases'])) {
                    foreach ($validatedData['testCases'] as $testCase) {
                        TestCase::create([
                            'questionID'     => $question->questionID,
                            'inputData'      => $testCase['inputData'] ?? "",
                            'expectedOutput' => $testCase['expectedOutput'],
                            'testCasePoints' => $testCase['testCasePoints'],
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message'    => 'Question updated successfully',
                'data'       => $question->load(['testCases', 'programmingLanguages']),
                'created_at' => $question->created_at->toDateTimeString(),
                'updated_at' => $question->updated_at->toDateTimeString(),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update question',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a question (and its test cases).
     */
    public function destroy($questionID)
    {
        $question = Question::find($questionID);

        if (!$question) {
            return response()->json(['message' => 'Question not found'], 404);
        }

        // Prevent deleting questions linked to an activity.
        if (ActivityQuestion::where('questionID', $questionID)->exists()) {
            return response()->json(['message' => 'Cannot delete: Question is linked to an activity.'], 403);
        }

        DB::beginTransaction();
        try {
            // Detach programming languages before deleting.
            $question->programmingLanguages()->detach();
            $question->delete();
            DB::commit();

            return response()->json(['message' => 'Question deleted successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete question',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}