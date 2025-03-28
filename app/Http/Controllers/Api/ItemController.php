<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Item; // formerly Question
use App\Models\TestCase;
use App\Models\ActivityItem; // formerly ActivityQuestion
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    /**
     * Get all items for a specific item type (with test cases & programming languages).
     * Optional query parameters:
     * - progLangID: filter by programming language(s)
     * - scope: 'personal' or 'global'
     * - teacherID: required when scope is 'personal'
     */
    public function getByItemType(Request $request, $itemTypeID)
    {
        $query = Item::where('itemTypeID', $itemTypeID)
            ->with(['testCases', 'programmingLanguages'])
            ->orderBy('updated_at', 'desc');

        // Filter by programming language if provided.
        if ($request->has('progLangID')) {
            $query->whereHas('programmingLanguages', function ($q) use ($request) {
                $q->whereIn('progLangID', (array)$request->progLangID);
            });
        }

        // Optional filtering based on scope.
        if ($request->has('scope')) {
            $scope = $request->scope;
            if ($scope === 'personal') {
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
     * Create a new item with multiple programming languages and conditional test cases.
     */
    public function store(Request $request)
    {
        // Retrieve the item type record.
        $itemType = DB::table('item_types')->where('itemTypeID', $request->itemTypeID)->first();
        if (!$itemType) {
            return response()->json(['message' => 'Invalid item type.'], 400);
        }

        // Define base rules.
        $rules = [
            'itemTypeID'         => 'required|exists:item_types,itemTypeID',
            'teacherID'          => 'nullable|exists:teachers,teacherID',
            'progLangIDs'        => 'required|array',
            'progLangIDs.*'      => 'exists:programming_languages,progLangID',
            'itemName'           => 'required|string|max:255', // was questionName
            'itemDesc'           => 'required|string',           // was questionDesc
            'itemDifficulty'     => 'required|in:Beginner,Intermediate,Advanced', // was questionDifficulty
            'itemPoints'         => 'required|numeric|min:1',     // was questionPoints
        ];

        // Enforce test case rules only for Console App.
        if ($itemType->itemTypeName === 'Console App') {
            $rules['testCases'] = 'required|array';
            $rules['testCases.*.inputData'] = 'nullable|string';
            $rules['testCases.*.expectedOutput'] = 'required|string';
            $rules['testCases.*.testCasePoints'] = 'required|numeric|min:0';
            $rules['testCases.*.isHidden'] = 'sometimes|boolean';
        } else {
            $rules['testCases'] = 'nullable|array';
            $rules['testCases.*.isHidden'] = 'sometimes|boolean';
        }

        $validatedData = $request->validate($rules);

        DB::beginTransaction();

        try {
            // Create the item.
            $item = Item::create([
                'itemTypeID'     => $validatedData['itemTypeID'],
                'teacherID'      => $request->teacherID ?? null,
                'itemName'       => $validatedData['itemName'],
                'itemDesc'       => $validatedData['itemDesc'],
                'itemDifficulty' => $validatedData['itemDifficulty'],
                'itemPoints'     => $validatedData['itemPoints'],
            ]);

            // Attach programming languages.
            $item->programmingLanguages()->attach($validatedData['progLangIDs']);

            // Process test cases if provided.
            if (!empty($validatedData['testCases'])) {
                $numTestCases = count($validatedData['testCases']);
                $totalPoints = $validatedData['itemPoints'];
                // Calculate the raw (exact) value for each test case.
                $rawValue = $totalPoints / $numTestCases;
                
                // Use the same raw value for each test case.
                foreach ($validatedData['testCases'] as $testCase) {
                     TestCase::create([
                        'itemID'         => $item->itemID,
                        'inputData'      => $testCase['inputData'] ?? "",
                        'expectedOutput' => $testCase['expectedOutput'],
                        'testCasePoints' => $rawValue,
                        'isHidden'       => $testCase['isHidden'] ?? false,
                     ]);
                }
            }

            DB::commit();

            return response()->json([
                'message'    => 'Item created successfully',
                'data'       => $item->load(['testCases', 'programmingLanguages']),
                'created_at' => $item->created_at->toDateTimeString(),
                'updated_at' => $item->updated_at->toDateTimeString(),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create item',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single item by ID (with test cases & programming languages).
     */
    public function show($itemID)
    {
        $item = Item::with(['testCases', 'programmingLanguages'])->find($itemID);

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        return response()->json([
            'item'       => $item,
            'created_at' => $item->created_at->toDateTimeString(),
            'updated_at' => $item->updated_at->toDateTimeString()
        ]);
    }

    /**
     * Update an item, including its programming languages and conditional test cases.
     */
    public function update(Request $request, $itemID)
    {
        $item = Item::find($itemID);
    
        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }
    
        // Retrieve the item type.
        $itemType = DB::table('item_types')->where('itemTypeID', $request->itemTypeID)->first();
        if (!$itemType) {
            return response()->json(['message' => 'Invalid item type.'], 400);
        }
    
        // Define base rules.
        $rules = [
            'itemTypeID'         => 'required|exists:item_types,itemTypeID',
            'progLangIDs'        => 'required|array',
            'progLangIDs.*'      => 'exists:programming_languages,progLangID',
            'itemName'           => 'required|string|max:255',
            'itemDesc'           => 'required|string',
            'itemDifficulty'     => 'required|in:Beginner,Intermediate,Advanced',
            'itemPoints'         => 'required|numeric|min:1',
        ];
    
        // Enforce test case rules only for Console App.
        if ($itemType->itemTypeName === 'Console App') {
            $rules['testCases'] = 'required|array';
            $rules['testCases.*.inputData'] = 'nullable|string';
            $rules['testCases.*.expectedOutput'] = 'required|string';
            // Although teachers provide a value, we'll auto-distribute, so this rule is here for validation.
            $rules['testCases.*.testCasePoints'] = 'required|numeric|min:0';
            $rules['testCases.*.isHidden'] = 'sometimes|boolean';
        } else {
            $rules['testCases'] = 'nullable|array';
            $rules['testCases.*.isHidden'] = 'sometimes|boolean';
        }
    
        $validatedData = $request->validate($rules);
    
        DB::beginTransaction();
    
        try {
            // Update item details.
            $item->update([
                'itemTypeID'     => $validatedData['itemTypeID'],
                'itemName'       => $validatedData['itemName'],
                'itemDesc'       => $validatedData['itemDesc'],
                'itemDifficulty' => $validatedData['itemDifficulty'],
                'itemPoints'     => $validatedData['itemPoints'],
            ]);
    
            // Sync programming languages.
            $item->programmingLanguages()->sync($validatedData['progLangIDs']);
    
            // Update test cases: delete existing and add new ones with auto-distribution.
            if ($request->has('testCases')) {
                TestCase::where('itemID', $item->itemID)->delete();
                if (!empty($validatedData['testCases'])) {
                    $numTestCases = count($validatedData['testCases']);
                    $totalPoints = $validatedData['itemPoints'];
                    // Calculate the raw value for each test case.
                    $rawValue = $totalPoints / $numTestCases;
                    
                    // Create each test case with the same raw value.
                    foreach ($validatedData['testCases'] as $testCase) {
                        TestCase::create([
                            'itemID'         => $item->itemID,
                            'inputData'      => $testCase['inputData'] ?? "",
                            'expectedOutput' => $testCase['expectedOutput'],
                            'testCasePoints' => $rawValue,
                            'isHidden'       => $testCase['isHidden'] ?? false,
                        ]);
                    }
                }
            }
    
            // -----------------------------------------------------------
            // 1) Now recalc the sum of all testCasePoints for this item
            // -----------------------------------------------------------
            $sumOfTestCases = TestCase::where('itemID', $item->itemID)->sum('testCasePoints');
    
            // -----------------------------------------------------------
            // 2) Update the itemPoints to match that sum
            // -----------------------------------------------------------
            $item->update(['itemPoints' => $sumOfTestCases]);
    
            // -----------------------------------------------------------
            // 3) Update the pivot table "activity_items" with the new points
            // -----------------------------------------------------------
            $activityItems = ActivityItem::where('itemID', $item->itemID)->get();
            foreach ($activityItems as $activityItem) {
                $activityItem->update(['actItemPoints' => $sumOfTestCases]);
            }
    
            // -----------------------------------------------------------
            // 4) Update the Activity's maxPoints if this item belongs to an activity
            // -----------------------------------------------------------
            if ($activityItems->isNotEmpty()) {
                $activityID = $activityItems->first()->actID;
                $activity = \App\Models\Activity::with('items')->find($activityID);
    
                if ($activity) {
                    // Re-sum all actItemPoints for the items in this activity
                    $newTotal = $activity->items->sum('actItemPoints');
                    $activity->update(['maxPoints' => $newTotal]);
                }
            }
    
            DB::commit();
    
            return response()->json([
                'message'    => 'Item updated successfully',
                'data'       => $item->load(['testCases', 'programmingLanguages']),
                'created_at' => $item->created_at->toDateTimeString(),
                'updated_at' => $item->updated_at->toDateTimeString(),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update item',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
      

    /**
     * Delete an item (and its test cases).
     */
    public function destroy($itemID)
    {
        $item = Item::find($itemID);

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        // Prevent deletion if the item is linked to an activity.
        if (ActivityItem::where('itemID', $itemID)->exists()) {
            return response()->json(['message' => 'Cannot delete: Item is linked to an activity.'], 403);
        }

        DB::beginTransaction();
        try {
            $item->programmingLanguages()->detach();
            $item->delete();
            DB::commit();

            return response()->json(['message' => 'Item deleted successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete item',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}