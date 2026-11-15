<?php

namespace App\Http\Controllers;

use App\Models\DailySummary;
use App\Models\Device;
use App\Models\HrFlag;
use App\Models\LocationRecord;
use App\Models\LocationStatusEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecordController extends Controller
{
    /**
     * Tables that belong to one device + user and must have device_uuid/
     * phone_number translated into device_id/user_id before saving.
     */
    private const DEVICE_SCOPED_TYPES = [
        'LocationRecord',
        'LocationStatusEvent',
        'DailySummary',
    ];

    public function store(Request $request)
    {
        try {
            // --- FIX 1: decode the JSON array properly instead of
            // string-replacing brackets and exploding on commas. That old
            // approach broke on any object containing more than one field,
            // which is every record this app ever sends.
            $type = $request->input('type');
            $items = $request->input('data');

            if (!is_array($items)) {
                // If the client sent `data` as a JSON string instead of a
                // parsed array, decode it here as a fallback.
                $items = json_decode($items, true) ?? [];
            }

            $info = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                // --- FIX 2: resolve device_uuid -> device_id and
                // phone_number -> user_id for every device-scoped table.
                if (in_array($type, self::DEVICE_SCOPED_TYPES, true)) {
                    $deviceUuid = $item['device_uuid'] ?? null;

                    if (!$deviceUuid) {
                        return response(['ok' => false, 'error' => 'device_uuid is required'], 422);
                    }

                    $device = Device::where('device_uuid', $deviceUuid)->first();

                    if (!$device) {
                        // The app must register (type: "User") before sending
                        // any location/status/summary data for this install.
                        return response(['ok' => false, 'error' => 'device not registered'], 409);
                    }

                    // Replace the install-level identifier with the real
                    // foreign keys the table actually has.
                    unset($item['device_uuid']);
                    $item['device_id'] = $device->id;
                    $item['user_id'] = $device->user_id;
                }

                $clientUuid = $item['client_uuid'] ?? null;

                switch ($type) {
                    case 'User':
                        // --- FIX 3: registering a user must also create (or
                        // reuse) the matching device row, and must not fail
                        // or duplicate when the same phone/device registers
                        // again.
                        $info[] = $this->upsertUserAndDevice($item);
                        break;

                    case 'LocationRecord':
                        $info[] = $this->upsertByClientUuid(LocationRecord::class, $item, $clientUuid);
                        break;

                    case 'LocationStatusEvent':
                        $info[] = $this->upsertByClientUuid(LocationStatusEvent::class, $item, $clientUuid);
                        break;

                    case 'DailySummary':
                        $info[] = $this->upsertByClientUuid(DailySummary::class, $item, $clientUuid);
                        break;

                    case 'Device':
                    case 'HrFlag':
                        // These are server-only concepts; the app never
                        // sends them. Reject explicitly instead of silently
                        // creating a row with whatever was posted.
                        return response(['ok' => false, 'error' => "type '$type' is not accepted from the app"], 422);

                    default:
                        return response(['ok' => false, 'error' => 'unknown type'], 422);
                }
            }

            return response(['ok' => true, 'data' => $info], 201);
        } catch (\Exception $exception) {
            // --- FIX 4: never return a raw exception. That is what exposed
            // the source file and server paths in the Laravel debug error
            // page. Make sure APP_DEBUG=false in .env as well; this catch
            // alone is not a substitute for that.
            report($exception);
            return response(['ok' => false, 'error' => 'internal error'], 500);
        }
    }

    /**
     * Create the user if the phone number is new, and always ensure a
     * device row exists for this device_uuid, linked to that user.
     * Safe to call again for the same phone/device (idempotent).
     */
    private function upsertUserAndDevice(array $item)
    {
        $phone = $item['phone_number'] ?? null;
        $deviceUuid = $item['device_uuid'] ?? null;
        $registeredAt = $item['registered_at'] ?? now();

        if (!$phone || !$deviceUuid) {
            throw new \InvalidArgumentException('phone_number and device_uuid are required');
        }

        return DB::transaction(function () use ($phone, $deviceUuid, $registeredAt) {
            $user = User::firstOrCreate(
                ['phone_number' => $phone]
            );

            $device = Device::firstOrNew(['device_uuid' => $deviceUuid]);
            $device->user_id = $user->id;
            $device->registered_at = $registeredAt;
            $device->last_seen_at = now();
            $device->save();

            return ['user' => $user, 'device' => $device];
        });
    }

    /**
     * Insert a row, or update it in place if a row with the same
     * client_uuid already exists. This is what makes retries after a lost
     * response safe: the same client_uuid never creates a second row.
     */
    private function upsertByClientUuid(string $modelClass, array $item, ?string $clientUuid)
    {
        if (!$clientUuid) {
            throw new \InvalidArgumentException('client_uuid is required');
        }

        $row = $modelClass::updateOrCreate(
            ['client_uuid' => $clientUuid],
            $item
        );

        // Keep device last_seen_at fresh on every accepted batch.
        if (isset($item['device_id'])) {
            Device::where('id', $item['device_id'])->update(['last_seen_at' => now()]);
        }

        return $row;
    }

    public function index(Request $request)
    {
        try {
            $info = [];
            switch ($request['type']) {
                case 'User':
                    $info = User::orderByDesc('id')->paginate(20);
                    break;
                case 'LocationRecord':
                    $info = LocationRecord::orderByDesc('id')->paginate(20);
                    break;
                case 'LocationStatusEvent':
                    $info = LocationStatusEvent::orderByDesc('id')->paginate(20);
                    break;
                case 'Device':
                    $info = Device::orderByDesc('id')->paginate(20);
                    break;
                case 'DailySummary':
                    $info = DailySummary::orderByDesc('id')->paginate(20);
                    break;
                case 'HrFlag':
                    $info = HrFlag::orderByDesc('id')->paginate(20);
                    break;
            }
            return response(['ok' => true, 'data' => $info], 200);
        } catch (\Exception $exception) {
            report($exception);
            return response(['ok' => false, 'error' => 'internal error'], 500);
        }
    }

    public function show(Request $request)
    {
        try {
            $info = [];
            switch ($request['type']) {
                case 'User':
                    $info = User::findOrFail($request['id']);
                    break;
                case 'LocationRecord':
                    $info = LocationRecord::findOrFail($request['id']);
                    break;
                case 'LocationStatusEvent':
                    $info = LocationStatusEvent::findOrFail($request['id']);
                    break;
                case 'Device':
                    $info = Device::findOrFail($request['id']);
                    break;
                case 'DailySummary':
                    $info = DailySummary::findOrFail($request['id']);
                    break;
                case 'HrFlag':
                    $info = HrFlag::findOrFail($request['id']);
                    break;
            }
            return response(['ok' => true, 'data' => $info], 200);
        } catch (\Exception $exception) {
            report($exception);
            return response(['ok' => false, 'error' => 'internal error'], 500);
        }
    }
}
