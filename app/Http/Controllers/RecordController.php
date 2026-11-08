<?php

namespace App\Http\Controllers;

use App\Models\DailySummary;
use App\Models\Device;
use App\Models\HrFlag;
use App\Models\LocationRecord;
use App\Models\LocationStatusEvent;
use App\Models\User;
use Illuminate\Http\Request;

class RecordController extends Controller
{
    public function store(Request $request)
    {
        try {
            $x = explode(',' ,$request['data']);
            foreach($x as $item){
                $item = json_decode($item);
            }
            return $x;
            $info = [];
            foreach($d as $item) {
                switch ($request['type']) {
                    case 'User':
                    {
                        $info[] = User::create($item);
                        break;
                    }
                    case 'LocationRecord':
                    {
                        $info[] = LocationRecord::create($item);
                        break;
                    }
                    case 'LocationStatusEvent':
                    {
                        $info[] = LocationStatusEvent::create($item);
                        break;
                    }
                    case 'Device':
                    {
                        $info[] = Device::create($item);
                        break;
                    }
                    case 'DailySummary':
                    {
                        $info[] = DailySummary::create($item);
                        break;
                    }
                    case 'HrFlag':
                    {
                        $info[] = HrFlag::create($item);
                        break;
                    }


                }
            }
            return response(['ok'=> true,'data'=>$info], 201);
        } catch (\Exception $exception) {
            return $exception;

        }

    }

    public function index(Request $request)
    {
        try {
            $info = [];
            switch ($request['type']) {
                case 'User':
                {
                    $info = User::orderByDesc('id')->paginate(20);
                    break;
                }
                case 'LocationRecord':
                {
                    $info = LocationRecord::orderByDesc('id')->paginate(20);
                    break;
                }
                case 'LocationStatusEvent':
                {
                    $info = LocationStatusEvent::orderByDesc('id')->paginate(20);
                    break;
                }
                case 'Device':
                {
                    $info = Device::orderByDesc('id')->paginate(20);
                    break;
                }
                case 'DailySummary':
                {
                    $info = DailySummary::orderByDesc('id')->paginate(20);
                    break;
                }
                case 'HrFlag':
                {
                    $info = HrFlag::orderByDesc('id')->paginate(20);
                    break;
                }
            }
            return response(['ok'=> true,'data'=>$info], 200);
        } catch (\Exception $exception) {
            return $exception;

        }

    }
    public function show(Request $request)
    {
        try {
            $info = [];
            switch ($request['type']) {
                case 'User':
                {
                    $info = User::findOrFail($request['id'])->first();
                    break;
                }
                case 'LocationRecord':
                {
                    $info = LocationRecord::findOrFail($request['id'])->first();
                    break;
                }
                case 'LocationStatusEvent':
                {
                    $info = LocationStatusEvent::findOrFail($request['id'])->first();
                    break;
                }
                case 'Device':
                {
                    $info = Device::findOrFail($request['id'])->first();
                    break;
                }
                case 'DailySummary':
                {
                    $info = DailySummary::findOrFail($request['id'])->first();
                    break;
                }
                case 'HrFlag':
                {
                    $info = HrFlag::findOrFail($request['id'])->first();
                    break;
                }
            }
            return response(['ok'=> true,'data'=>$info], 200);
        } catch (\Exception $exception) {
            return $exception;

        }

    }
}
