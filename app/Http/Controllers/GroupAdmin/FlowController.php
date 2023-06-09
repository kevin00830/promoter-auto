<?php

namespace App\Http\Controllers\GroupAdmin;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\FlowSavedPath;
use App\Models\MenuConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FlowController extends Controller
{
    // Show the flowbuilder view for creating and editing flows.
    public function flowbuilder(Request $request)
    {
        $group_id =auth()->user()->id;
        $flow_saved_path =  DB::connection('mysql2')->table('flow_saved_path')->where('group_id',$group_id)->get();
        $fieldname = DB::connection('mysql2')->table('flows')->where('group_id',$group_id)->get();
        return view('groupadmin.dashboard.flowbuilder', compact('flow_saved_path', 'fieldname'));
    }

    // Upload image related to user id
    public function uploadImage(Request $request)
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        $file = $request->file('file');

        $group_id = auth()->user()->id; // Get user group id
//        $extension = $file->getClientOriginalExtension();
//        $imageName = time().'.'.$extension; // create a unique file name for the image
        $imageName = $file->getClientOriginalName();

        $file->move(public_path('uploads/'. $group_id), $imageName); // move the image to the server folder

        $returnName = 'uploads/'. $group_id. '/'. $imageName;
        return response()->json(['fileName' => $returnName]);
    }

    // Add flow data
    public function addFlow(Request $request)
    {
        $data = json_decode($request->getContent(), true); // decode the JSON payload sent by the front-end
        $group_id =auth()->user()->id; // Get authenticated user group_id

        // delete existing data by group_id in `flows` and `menu_connections` table.
        DB::connection('mysql2')->table('flows')->where('group_id', $group_id)->delete();
        DB::connection('mysql2')->table('menu_connections')->where('group_id', $group_id)->delete();

        // save new data
        foreach($data['Home']['data'] as $node) {

            // customize file path begin
            $originPath = $node['data']['imagepath'] ?? '';
            dd($originPath);
            if (substr($originPath, 0, 12) == "C:\\fakepath\\") {
                $customizedPath = substr($originPath, 12); // modern browser
            }
            $x = strrpos($originPath, '/');
            if ($x !== false) { // Unix-based path
                $customizedPath = substr($originPath, $x + 1);
            }
            $x = strrpos($originPath, '\\');
            if ($x !== false) { // Windows-based path
                $customizedPath = substr($originPath, $x + 1);
            }
            if ($originPath == '') {
                $customizedPath = '';
            }
            // customize file path end


            $record = new Flow;
            $record->flow_id = $node['id'];
            $record->group_id = $group_id;
            $record->keywords = $node['data']['keyword'];
            $record->fieldname = $node['data']['fieldname'] ?? '';
            $record->next = '';
            $record->tmp_type = $node['data']['type'];
            $record->auto_flow = '';
            $record->reply = $node['data']['message'] ?? '';
            $record->image_link = $customizedPath ? 'https://auto.notifire-api.com/uploads/'. $group_id .'/'. $customizedPath : '';
            $record->delay = $node['data']['delay'] ?? 3;
            $record->url = $node['data']['url'] ?? '';
            $record->save(); // this will auto-generate the ID for the record

            if(empty(($node['outputs']['output_1']['connections']))) {
                // if output connections are null, set 'next' column to 0
                $next = 0;
            } else {
                if (count($node['outputs']['output_1']['connections']) == 1 && count($node['outputs']) == 1) {
                    // if output connections exist, set 'next' column to 1
                    $next = 1;

                    // find the related flow record
                    $record->auto_flow = $node['outputs']['output_1']['connections'][0]['node'];
                } else if(count($node['outputs']['output_1']['connections']) > 1) {
                    // if output connections are more than 1, set 'next' column to 0 and tmp_type to 500
                    $next = 0;

                    //save data in menu_connections table
                    foreach($node['outputs']['output_1']['connections'] as $menu_node) {

                        $child_flow_id = $menu_node['node'];
                        $menu = new MenuConnection();
                        $menu->menu_flow_id = $node['id'];
                        $menu->group_id = $group_id;
                        $menu->child_flow_id = $child_flow_id;
                        $menu->keywords = $data['Home']['data'][$child_flow_id]['data']['keyword'];
                        $menu->save(); // this will auto-generate the ID for the record
                    }
                } else {
                    $next = 0;
                }

                // set cond for conditional reply
                if($node['data']['type'] == 1000) {
                    $previous_flow_id = $node['id'] - 1;
                    $record->fieldname = $data['Home']['data'][$previous_flow_id]['data']['fieldname'];
                    $record->cond_opt = $node['data']['condopt'];
                    $record->reply = $node['data']['condval'];
                    $record->cond_true = $node['outputs']['output_1']['connections'][0]['node'];
                    $record->cond_false = $node['outputs']['output_2']['connections'][0]['node'];
                }
            }

            // update 'next' column
            $record->next = $next;
            $record->save();
        }

        // Return a response indicating success.
        return response()->json(['message' => 'Data saved successfully']);
    }

    // Export flow to Json in server
    public function exportJson(Request $request)
    {
        $group_id =auth()->user()->id; // Get authenticated user group_id
        $fileName = $request['exportJsonName'];

        File::put(public_path("uploads/{$group_id}/{$request['exportJsonName']}.json"), $request['data']); // write the JSON to a file on the server

        $record = FlowSavedPath::firstOrCreate(
            ['flow_name' => $fileName],
            [
                'path' => "https://auto.notifire-api.com/uploads/{$group_id}/{$fileName}.json",
                'group_id' => $group_id,
            ]
        );

        return response()->json(['message' => 'Data exported successfully']);
    }

    // Import Json from server
    public function importJson(Request $request)
    {
        $group_id =auth()->user()->id; // Get authenticated user group_id

        $data = $request->getContent(); // get the file name of the JSON file from the request content
        $importData = File::get(public_path("uploads/{$group_id}/{$data}")); // read the JSON file data from the server
        return response()->json(['importData' => $importData]);
    }
}
