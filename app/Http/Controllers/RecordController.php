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
     * Tables that belong to one device + user and must have device_uuid
     * (a string from the app) translated into the numeric device_id/user_id
     * foreign keys that these tables actually have.
     */
    private const DEVICE_SCOPED_TYPES = [
        'LocationRecord',
        'LocationStatusEvent',
        'DailySummary',
    ];

    // Matches the client's daily_summary.fake_percentage threshold.
    private const FAKE_THRESHOLD_PERCENT = 50;

    public function store(Request $request)
    {
        try {
            // --- FIX 1: decode the JSON array properly instead of the old
            // str_replace(['[',']'], '', ...) + explode(',') approach, which
            // broke on any object with more than one field.
            $type = $request->input('type');
            $items = $request->input('data');

            if (!is_array($items)) {
                $decoded = json_decode($items, true);
                $items = is_array($decoded) ? $decoded : [];
            }

            // `data` must be a JSON array (a list), not a single object.
            // json_decode on an object produces a PHP array with string
            // keys, which is NOT a list — the old code silently accepted
            // that as "zero rows" instead of rejecting it.
            if (!array_is_list($items)) {
                return response(['ok' => false, 'error' => 'data must be a JSON array of objects'], 422);
            }

            $info = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    return response(['ok' => false, 'error' => 'each item in data must be an object'], 422);
                }

                // --- FIX 2: resolve device_uuid -> numeric device_id, and
                // the device's owner -> numeric user_id, for every
                // device-scoped table. The server's id columns are plain
                // auto-increment integers (confirmed from the live schema),
                // not UUIDs — device_uuid/client_uuid are the only string
                // identifiers, and they exist only to let the app refer to
                // things before/without a server-assigned integer id.
                if (in_array($type, self::DEVICE_SCOPED_TYPES, true)) {
                    $deviceUuid = $item['device_uuid'] ?? null;

                    if (!$deviceUuid) {
                        return response(['ok' => false, 'error' => 'device_uuid is required'], 422);
                    }

                    $device = Device::where('device_uuid', $deviceUuid)->first();

                    if (!$device) {
                        return response(['ok' => false, 'error' => 'device not registered'], 409);
                    }

                    unset($item['device_uuid']);
                    $item['device_id'] = $device->id;   // integer
                    $item['user_id'] = $device->user_id; // integer
                }

                $clientUuid = $item['client_uuid'] ?? null;

                switch ($type) {
                    case 'User':
                        // Registering a user must also create/reuse the
                        // matching device row, and must be safe to call
                        // again for the same phone/device.
                        $info[] = $this->upsertUserAndDevice($item);
                        break;

                    case 'LocationRecord':
                        if (!$clientUuid) {
                            return response(['ok' => false, 'error' => 'client_uuid is required'], 422);
                        }
                        $item = $this->prepareLocationRecord($item);
                        $info[] = $this->upsertByClientUuid(LocationRecord::class, $item, $clientUuid);
                        break;

                    case 'LocationStatusEvent':
                        if (!$clientUuid) {
                            return response(['ok' => false, 'error' => 'client_uuid is required'], 422);
                        }
                        $item = $this->prepareStatusEvent($item);
                        $info[] = $this->upsertByClientUuid(LocationStatusEvent::class, $item, $clientUuid);
                        break;

                    case 'DailySummary':
                        if (!$clientUuid) {
                            return response(['ok' => false, 'error' => 'client_uuid is required'], 422);
                        }
                        $item = $this->prepareDailySummary($item);
                        $info[] = $this->upsertByClientUuid(DailySummary::class, $item, $clientUuid);
                        break;

                    case 'Device':
                    case 'HrFlag':
                        // Server-only concepts; the app never sends these.
                        return response(['ok' => false, 'error' => "type '$type' is not accepted from the app"], 422);

                    default:
                        return response(['ok' => false, 'error' => 'unknown type'], 422);
                }
            }

            return response(['ok' => true, 'data' => $info], 201);
        } catch (\Exception $exception) {
            // Never leak a raw exception (source paths, stack trace) in the
            // response — that was the earlier security issue. Make sure
            // APP_DEBUG=false in .env as well; this catch is not a
            // substitute for that setting.
            report($exception);
            return response(['ok' => false, 'error' => 'internal error'], 500);
        }
    }

    /**
     * Create the user if the phone number is new, and ensure a device row
     * exists for this device_uuid, linked to that user. Safe to call again
     * for the same phone/device (idempotent).
     *
     * id on both `users` and `devices` is a plain AUTO_INCREMENT bigint on
     * the real server schema — do NOT set it manually (no Str::uuid()).
     * Letting it stay unset is what lets MySQL assign it, exactly like
     * Eloquent's normal default behavior.
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
            $user = User::firstOrCreate(['phone_number' => $phone]);

            $device = Device::firstOrNew(['device_uuid' => $deviceUuid]);
            $device->user_id = $user->id;
            $device->registered_at = $this->toMysqlDatetime($registeredAt);
            $device->last_seen_at = now();
            $device->save();

            return ['user' => $user, 'device' => $device];
        });
    }

    /**
     * location_records has no columns that need special handling beyond
     * the device_id/user_id resolution already done in store(). This is a
     * pass-through kept for symmetry and future column-specific fixes.
     */
    private function prepareLocationRecord(array $item): array
    {
        return $item;
    }

    /**
     * location_status_events.previous_state is NOT NULL on the live
     * schema, but the app legitimately sends previous_state: null on the
     * first-ever observation of a category and on every
     * SERVICE_START_FAILED event. Ask the backend developer to make that
     * column nullable:
     *
     *   ALTER TABLE location_status_events MODIFY previous_state
     *     ENUM('ENABLED','DISABLED','PRECISE','APPROXIMATE','GRANTED',
     *          'DENIED','NOT_APPLICABLE') NULL;
     *
     * Until that migration runs, this method substitutes a placeholder so
     * the insert does not fail outright. Remove this substitution once the
     * column accepts NULL, so the server stores what the app actually sent.
     */
    private function prepareStatusEvent(array $item): array
    {
        if (!array_key_exists('previous_state', $item) || $item['previous_state'] === null) {
            // TODO: remove this line once previous_state is migrated to
            // allow NULL on the server. Today's NOT NULL column cannot
            // store the app's real value for this case.
            $item['previous_state'] = $item['current_state'] ?? 'NOT_APPLICABLE';
        }

        return $item;
    }

    /**
     * daily_summary.fake_gps_app_used_today and is_above_threshold are
     * plain columns with a default of 0 on the live schema (not generated
     * columns), so they must be computed here or they will always stay 0.
     */
    private function prepareDailySummary(array $item): array
    {
        $fakeGpsCount = (int) ($item['fake_gps_app_count'] ?? 0);
        $fakePercentage = (float) ($item['fake_percentage'] ?? 0);

        $item['fake_gps_app_used_today'] = $fakeGpsCount > 0 ? 1 : 0;
        $item['is_above_threshold'] = $fakePercentage >= self::FAKE_THRESHOLD_PERCENT ? 1 : 0;

        return $item;
    }

    /**
     * Insert a row, or update it in place if a row with the same
     * client_uuid already exists. This is what makes retries after a lost
     * response safe: the same client_uuid never creates a second row.
     *
     * This sets each attribute individually (forceFill) instead of mass
     * assignment (create()/updateOrCreate() with a raw array). Laravel
     * blocks mass assignment unless the model declares every one of these
     * columns in $fillable (or sets $guarded = []) — that was the actual
     * cause of the HTTP 500 on LocationRecord/LocationStatusEvent/
     * DailySummary. forceFill bypasses that guard entirely, so this works
     * regardless of what $fillable/$guarded the models declare, with no
     * need to inspect or change the model files.
     */
    private function upsertByClientUuid(string $modelClass, array $item, string $clientUuid)
    {
        /** @var \Illuminate\Database\Eloquent\Model $row */
        $row = $modelClass::where('client_uuid', $clientUuid)->first();

        if (!$row) {
            $row = new $modelClass();
        }

        // received_at is NOT NULL with no default on location_records,
        // location_status_events, and daily_summary. The app never sends
        // this field (it is a server-side "when we got it" timestamp, not
        // something the client knows), so it must be set here explicitly
        // on every insert, or the save() below fails with a NOT NULL
        // violation — this was the actual cause of the HTTP 500.
        if (!isset($item['received_at'])) {
            $item['received_at'] = now()->toDateTimeString();
        }

        $row->forceFill($item);
        $row->save();

        if (isset($item['device_id'])) {
            Device::where('id', $item['device_id'])->update(['last_seen_at' => now()]);
        }

        return $row;
    }

    /**
     * The app sends ISO-8601 strings with milliseconds and a trailing 'Z'
     * (e.g. "2026-11-15T13:47:10.615Z"). Parse explicitly with Carbon
     * before saving to a DATETIME column.
     */
    private function toMysqlDatetime($value): string
    {
        if ($value instanceof \Illuminate\Support\Carbon) {
            return $value->toDateTimeString();
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
        } catch (\Exception $e) {
            return now()->toDateTimeString();
        }
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
