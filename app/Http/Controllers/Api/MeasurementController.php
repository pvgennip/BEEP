<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Auth;
use App\User;
use App\Device;
use App\Category;
use App\Measurement;
use App\Models\FlashLog;
use App\Models\Webhook;
// use App\Transformer\SensorTransformer;
use Validator;
use InfluxDB;
use Response;
use Moment\Moment;
use League\Fractal;
use App\Http\Requests\PostSensorRequest;
use App\Traits\MeasurementLegacyCalculationsTrait;
use App\Traits\MeasurementLoRaDecoderTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

use Illuminate\Support\Facades\Cache;

/**
 * @group Api\MeasurementController
 * Store and retreive sensor data (both LoRa and API POSTs) from a Device
 */
class MeasurementController extends Controller
{
    use MeasurementLegacyCalculationsTrait, MeasurementLoRaDecoderTrait;

    protected $respose;
    protected $valid_sensors  = [];
    protected $output_sensors = [];
    protected $precision      = 's';
    protected $timeFormat     = 'Y-m-d H:i:s';
    protected $maxDataPoints  = 5000;
 
    public function __construct()
    {
        // make sure to add to the measurements DB table w_v_kg_per_val, w_fl_kg_per_val, etc. and w_v_offset, w_fl_offset to let the calibration functions function correctly
        $this->valid_sensors  = Measurement::all()->pluck('pq', 'abbreviation')->toArray();
        $this->output_sensors = Measurement::where('show_in_charts', '=', 1)->pluck('abbreviation')->toArray();
        $this->client         = new \Influx;
        //die(print_r($this->valid_sensors));
    }
   
    private function doPostHttpRequest($url, $data)
    {
        $guzzle   = new Client();
        try
        {
            $response = $guzzle->post($url, [\GuzzleHttp\RequestOptions::JSON => $data]);
        }
        catch(ClientException $e)
        {
            return $e;
        }

        return $response;
    }

    // Sensor measurement functions

    protected function get_user_device(Request $request, $mine = false)
    {
        $this->validate($request, [
            'id'        => 'nullable|integer|exists:sensors,id',
            'key'       => 'nullable|integer|exists:sensors,key',
            'hive_id'   => 'nullable|integer|exists:hives,id',
        ]);
        
        $devices = $request->user()->allDevices($mine); // inlude user Group hive sensors ($mine == false)

        if ($devices->count() > 0)
        {
            if ($request->filled('id') && $request->input('id') != 'null')
            {
                $id = $request->input('id');
                $check_device = $devices->findOrFail($id);
            }
            else if ($request->filled('device_id') && $request->input('device_id') != 'null')
            {
                $id = $request->input('device_id');
                $check_device = $devices->findOrFail($id);
            }
            else if ($request->filled('key') && $request->input('key') != 'null')
            {
                $key = $request->input('key');
                $check_device = $devices->where('key', $key)->first();
            }
            else if ($request->filled('hive_id') && $request->input('hive_id') != 'null')
            {
                $hive_id = $request->input('hive_id');
                $check_device = $devices->where('hive_id', $hive_id)->first();
            }
            else
            {
                $check_device = $devices->first();
            }
            
            if(isset($check_device))
                return $check_device;
        }
        return Response::json('no_device_found', 404);
    }

    
    

    // requires at least ['name'=>value] to be set
    private function storeInfluxData($data_array, $dev_eui, $unix_timestamp)
    {
        // store posted data
        $client    = $this->client;
        $points    = [];
        $unix_time = isset($unix_timestamp) ? $unix_timestamp : time();

        $valid_sensor_keys = array_keys($this->valid_sensors);

        foreach ($data_array as $key => $value) 
        {
            if (in_array($key, $valid_sensor_keys) )
            {
                array_push($points, 
                    new InfluxDB\Point(
                        'sensors',                  // name of the measurement
                        null,                       // the measurement value
                        ['key' => $dev_eui],     // optional tags
                        ["$key" => floatval($value)], // key value pairs
                        $unix_time                  // Time precision has to be set to InfluxDB\Database::PRECISION_SECONDS!
                    )
                );
            }
        }
        //die(print_r($points));
        $stored = false;
        if (count($points) > 0)
        {
            try
            {
                $stored = $client::writePoints($points, InfluxDB\Database::PRECISION_SECONDS);
            }
            catch(\Exception $e)
            {
                // gracefully do nothing
            }
        }
        return $stored;
    }

    private function cacheRequestRate($name)
    {
        Cache::remember($name.'-time', 600, function () use ($name)
        { 
            Cache::forget($name.'-count'); 
            return time(); 
        });

        if (Cache::has($name.'-count'))
            Cache::increment($name.'-count');
        else
            Cache::put($name.'-count', 1);

    }

    private function storeMeasurements($data_array)
    {
        if (!in_array('key', array_keys($data_array)) || $data_array['key'] == '' || $data_array['key'] == null)
        {
            Storage::disk('local')->put('sensors/sensor_no_key.log', json_encode($data_array));
            $this->cacheRequestRate('store-measurements-400');
            return Response::json('No key provided', 400);
        }

        // Check if key is valid
        $dev_eui = $data_array['key']; // save sensor data under sensor key
        $device  = Device::where('key', $dev_eui)->first();
        if($device)
        {
            $battery_voltage = isset($data_array['bv']) ? floatval($data_array['bv']) : null;
            $this->storeDeviceMeta($dev_eui, 'battery_voltage', $battery_voltage);
        }
        else
        {
            Storage::disk('local')->put('sensors/sensor_invalid_key.log', json_encode($data_array));
            $this->cacheRequestRate('store-measurements-401');
            return Response::json('No valid key provided', 401);
        }

        unset($data_array['key']);

        $time = time();
        if (isset($data_array['time']))
            $time = intVal($data_array['time']);

        // New weight sensor data calculations based on sensor definitions for weight and internal temperature
        if (!isset($data_array['weight_kg']) && isset($data_array['w_v']))
        {
            $date = date($this->timeFormat, $time); 
            $data_array = $device->addSensorDefinitionMeasurements($data_array, $data_array['w_v'], 'w_v', $date);
        }
        if (isset($data_array['t_i']))
        {
            $date = date($this->timeFormat, $time); 
            $data_array = $device->addSensorDefinitionMeasurements($data_array, $data_array['t_i'], 't_i', $date);
        }
        
        // Legacy weight calculation from 2-4 load cells
        if (!isset($data_array['weight_kg']) && (isset($data_array['w_fl']) || isset($data_array['w_fr']) || isset($data_array['w_bl']) || isset($data_array['w_br']) || isset($data_array['w_v']))) 
        {
            // check if calibration is required
            $calibrate = $device->last_sensor_measurement_time_value('calibrating_weight');
            if (floatval($calibrate) > 0)
                $this->calibrate_weight_sensors($device, $calibrate, false, $data_array);

            if (!isset($data_array['weight_kg']))
            {
                // take into account offset and multi
                $weight_kg = $this->calculateWeightKg($device, $data_array);
                if (!isset($data_array['w_v']) || $data_array['w_v'] != $weight_kg) // do not save too big value
                    $data_array['weight_kg'] = $weight_kg;

                // check if we need to compensate weight for temp (legacy)
                //$data_array = $this->add_weight_kg_corrected_with_temperature($device, $data_array);
            }
        }

        $stored = $this->storeInfluxData($data_array, $dev_eui, $time);
        if($stored) 
        {
            $this->cacheRequestRate('store-measurements-201');
            return Response::json("saved", 201);
        } 
        else
        {
            //die(print_r($data_array));
            Storage::disk('local')->put('sensors/sensor_write_error.log', json_encode($data_array));
            $this->cacheRequestRate('store-measurements-500');
            return Response::json('sensor-write-error', 500);
        }
    }

    
    public function sensor_measurement_types_available(Request $request)
    {
        $device_id           = $request->input('device_id');
        $device              = $this->get_user_device($request);

        if ($device)
        {
            $start       = $request->input('start');
            $end         = $request->input('end');
            
            $tz          = $request->input('timezone', 'Europe/Amsterdam');
            $startMoment = new Moment($start, 'UTC');
            $startString = $startMoment->setTimezone($tz)->format($this->timeFormat); 
            $endMoment   = new Moment($end, 'UTC');
            $endString   = $endMoment->setTimezone($tz)->format($this->timeFormat);
            
            $sensors     = $request->input('sensors', $this->output_sensors);
            $where       = '("key" = \''.$device->key.'\' OR "key" = \''.strtolower($device->key).'\' OR "key" = \''.strtoupper($device->key).'\') AND time >= \''.$startString.'\' AND time <= \''.$endString.'\'';

            $sensor_measurements = Device::getAvailableSensorNamesFromData($sensors, $where, 'sensors', false);
            //die(print_r([$device->name, $device->key]));
            if ($sensor_measurements)
            {
                $measurement_types   = Measurement::all()->sortBy('pq')->whereIn('abbreviation', $sensor_measurements)->pluck('abbr_named_object','abbreviation')->toArray();
                return Response::json($measurement_types, 200);
            }
            else
            {
                return Response::json('influx-query-empty', 500);
            }
        }
        return Response::json('invalid-user-device', 500);
    }

    /**
    api/sensors/lastvalues GET
    Request last measurement values of all sensor measurements from a Device
    @authenticated
    @bodyParam key string DEV EUI to look up the Device
    @bodyParam id integer ID to look up the Device
    @bodyParam hive_id integer Hive ID to look up the Device
    */
    public function lastvalues(Request $request)
    {
        $device = $this->get_user_device($request);
        $output = $device->last_sensor_values_array(implode('","',$this->output_sensors));

        if ($output === false)
            return Response::json('sensor-get-error', 500);
        else if ($output !== null)
            return Response::json($output);

        return Response::json('error', 404);
    }


    private function parse_kpn_payload($request_data)
    {
        $data_array = [];
        //die(print_r($request_data));
        if (isset($request_data['LrnDevEui'])) // KPN Simpoint msg
            if (Device::all()->where('key', $request_data['LrnDevEui'])->count() > 0)
                $data_array['key'] = $request_data['LrnDevEui'];

        if (isset($request_data['DevEUI_uplink']['DevEUI'])) // KPN Simpoint msg
            if (Device::where('key', $request_data['DevEUI_uplink']['DevEUI'])->count() > 0)
                $data_array['key'] = $request_data['DevEUI_uplink']['DevEUI'];

        if (isset($request_data['DevEUI_location']['DevEUI'])) // KPN Simpoint msg
            if (Device::where('key', $request_data['DevEUI_location']['DevEUI'])->count() > 0)
                $data_array['key'] = $request_data['DevEUI_location']['DevEUI'];


        if (isset($request_data['DevEUI_uplink']['LrrRSSI']))
            $data_array['rssi'] = $request_data['DevEUI_uplink']['LrrRSSI'];
        if (isset($request_data['DevEUI_uplink']['LrrSNR']))
            $data_array['snr']  = $request_data['DevEUI_uplink']['LrrSNR'];
        if (isset($request_data['DevEUI_uplink']['LrrLAT']))
            $data_array['lat']  = $request_data['DevEUI_uplink']['LrrLAT'];
        if (isset($request_data['DevEUI_uplink']['LrrLON']))
            $data_array['lon']  = $request_data['DevEUI_uplink']['LrrLON'];

        if (isset($request_data['DevEUI_uplink']['payload_hex']))
            $data_array = array_merge($data_array, $this->decode_simpoint_payload($request_data['DevEUI_uplink']));

        //die(print_r($data_array));
        if (isset($data_array['beep_base']) && boolval($data_array['beep_base']) && isset($data_array['key']) && isset($data_array['hardware_id'])) // store hardware id
        {
            $this->storeDeviceMeta($data_array['key'], 'hardware_id', $data_array['hardware_id']);
            if (isset($data_array['measurement_transmission_ratio']))
                $this->storeDeviceMeta($data_array['key'], 'measurement_transmission_ratio', $data_array['measurement_transmission_ratio']);
            if (isset($data_array['measurement_interval_min']))
                $this->storeDeviceMeta($data_array['key'], 'measurement_interval_min', $data_array['measurement_interval_min']);
            if (isset($data_array['hardware_version']))
                $this->storeDeviceMeta($data_array['key'], 'hardware_version', $data_array['hardware_version']);
            if (isset($data_array['firmware_version']))
                $this->storeDeviceMeta($data_array['key'], 'firmware_version', $data_array['firmware_version']);
            if (isset($data_array['bootcount']))
                $this->storeDeviceMeta($data_array['key'], 'bootcount', $data_array['bootcount']);
        }


        if (isset($data_array['w_fl']) || isset($data_array['w_fr']) || isset($data_array['w_bl']) || isset($data_array['w_br'])) // v7 firmware
        {
            // - H   -> *2 (range 0-200)
            // - T   -> -10 -> +40 range (+10, *5), so 0-250 is /5, -10
            // - W_E -> -20 -> +80 range (/2, +10, *5), so 0-250 is /5, -10, *2
            $data_array = $this->floatify_sensor_val($data_array, 't');
            $data_array = $this->floatify_sensor_val($data_array, 't_i');
            $data_array = $this->floatify_sensor_val($data_array, 'h');
            $data_array = $this->floatify_sensor_val($data_array, 'bv');
            $data_array = $this->floatify_sensor_val($data_array, 'w_v');
            $data_array = $this->floatify_sensor_val($data_array, 'w_fl');
            $data_array = $this->floatify_sensor_val($data_array, 'w_fr');
            $data_array = $this->floatify_sensor_val($data_array, 'w_bl');
            $data_array = $this->floatify_sensor_val($data_array, 'w_br');
        }
        return $data_array;
    }
    
    private function storeDeviceMeta($key, $field=null, $value=null)
    {
        $device = Device::where('key', $key)->first();

        if ($device == null && $field == 'hardware_id' && $value !== null && env('ALLOW_DEVICE_CREATION') == 'true' && Auth::user() && Auth::user()->hasRole('sensor-data')) // no device with this key available, so create new device by hardware id
        {
            $device = Device::where('hardware_id', $value)->first();

            if (!isset($device) && strlen($value) == 18) // TODO: remove if TTN and app fix and DB change have been implemented
                $device = Device::where('hardware_id', '0e'.$value)->first();
            
            if ($device)
            {
                $device->key = $key; // update device key of hardware id to prevent double hardware id's
            }
            else
            {
                $category_id = Category::findCategoryIdByParentAndName('sensor', 'beep');
                $device_name = 'BEEPBASE-'.strtoupper(substr($key, -4, 4));
                $device      = Device::create(['name'=> $device_name, 'key'=>$key, 'hardware_id'=>$value, 'user_id'=>1, 'category_id'=>$category_id]);
            }
        }

        if($device)
        {
            if ($field != null && $value != null)
            {
                switch($field)
                {
                    case 'hardware_id':
                        if (isset($device->hardware_id)) 
                            return;
                        else
                            $device->hardware_id = $value;
                        break;
                    case 'time_device':
                        $device->datetime = date("Y-m-d H:i:s", $value);
                        $time = time();
                        $device->datetime_offset_sec = round($value - $time, 2);
                        break;
                    default:
                        $device->{$field} = $value;
                        break;

                }
            }
            // store metadata from sensor
            $device->last_message_received = date('Y-m-d H:i:s');
            $device->save();
        }
    }

    private function sendDeviceDownlink($key, $url)
    {
        $device = Device::where('key', $key)->first();

        if($device && isset($url) && isset($device->next_downlink_message)) // && Auth::user()->hasRole('sensor-data')
        {
            $msg = $device->next_downlink_message;
            $downlink_array = [
                'dev_id' => $key,
                'port' => 5,
                'confirmed' => true,
                'payload_raw' => base64_encode($msg),
            ];
            $result = $this->doPostHttpRequest($url, $downlink_array);

            // store waiting message for sensor
            if ($result instanceof ClientException)
            {
                $device->last_downlink_result  = 'Error (no result): last downlink ('.$msg.') tried to sent @ '.date('Y-m-d H:i:s').'. Error message: '.substr($result->getMessage(), 0, 150);
                $device->save();
            }
            else if ($result)
            {
                if ($result->getStatusCode() == 200)
                {
                    $device->next_downlink_message = null;
                    $device->last_downlink_result  = 'Last downlink ('.$msg.') sent @ '.date('Y-m-d H:i:s').', waiting for result...';
                    $device->save();
                }
                else
                {
                    $device->last_downlink_result  = 'Error (status '.$result->getStatusCode().'): last downlink ('.$msg.') tried to @ '.date('Y-m-d H:i:s');
                    $device->save();
                }
            }
        }
    }

    private function parse_ttn_payload($request_data)
    {
        $data_array = [];

        // parse payload
        if (isset($request_data['payload_fields'])) // TTN v2 with decoder installed
            $data_array = $request_data['payload_fields'];
        else if (isset($request_data['payload_raw'])) // TTN v2
            $data_array = $this->decode_ttn_payload($request_data);
        else if (isset($request_data['uplink_message']) && isset($request_data['end_device_ids'])) // TTN v3 (Things cloud)
            $data_array = $this->decode_ttn_payload($request_data);

        // store device metadata
        if (isset($data_array['beep_base']) && boolval($data_array['beep_base']) && isset($data_array['key']) && isset($data_array['hardware_id'])) // store hardware id
        {
            $this->storeDeviceMeta($data_array['key'], 'hardware_id', $data_array['hardware_id']);
            if (isset($data_array['measurement_transmission_ratio']))
                $this->storeDeviceMeta($data_array['key'], 'measurement_transmission_ratio', $data_array['measurement_transmission_ratio']);
            if (isset($data_array['measurement_interval_min']))
                $this->storeDeviceMeta($data_array['key'], 'measurement_interval_min', $data_array['measurement_interval_min']);
            if (isset($data_array['hardware_version']))
                $this->storeDeviceMeta($data_array['key'], 'hardware_version', $data_array['hardware_version']);
            if (isset($data_array['firmware_version']))
                $this->storeDeviceMeta($data_array['key'], 'firmware_version', $data_array['firmware_version']);
            if (isset($data_array['bootcount']))
                $this->storeDeviceMeta($data_array['key'], 'bootcount', $data_array['bootcount']);
            if (isset($data_array['time_device']))
                $this->storeDeviceMeta($data_array['key'], 'time_device', $data_array['time_device']);
        }

        // process downlink
        if (isset($data_array['key']) && isset($data['downlink_url']))
            $this->sendDeviceDownlink($data_array['key'], $data['downlink_url']);

        if (isset($data_array['key']) && isset($data_array['beep_base']) && boolval($data_array['beep_base']) && isset($data['port']) && $data['port'] == 6) // downlink response
        {
            $device = Device::where('key', $data_array['key'])->first();
            if($device) // && Auth::user()->hasRole('sensor-data')
            {
                $device->last_downlink_result = json_encode($data_array);
                $device->save();
            }
        }

        return $data_array;
    }


    /**
    api/lora_sensors POST
    Store sensor measurement data (see BEEP sensor data API definition) from TTN or KPN (Simpoint)
    When Simpoint payload is supplied, the LoRa HEX to key/value pairs decoding is done within function $this->parse_ttn_payload() 
    When TTN payload is supplied, the TTN HTTP integration decoder/converter is assumed to have already converted the payload from LoRa HEX to key/value conversion

    @bodyParam key string required DEV EUI of the Device to enable storing sensor data
    @bodyParam payload_fields array TTN Measurement data
    @bodyParam DevEUI_uplink array KPN Measurement data
    */
    public function lora_sensors(Request $request)
    {
        $data_array   = [];
        $request_data = $request->input();
        $payload_type = '';

        // distinguish type of data
        if ($request->filled('LrnDevEui') && $request->filled('DevEUI_uplink.payload_hex')) // KPN/Simpoint HTTPS POST
        {
            $data_array = $this->parse_kpn_payload($request_data);
            $payload_type = 'kpn';
        }
        else if (($request->filled('payload_fields') || $request->filled('payload_raw')) && $request->filled('hardware_serial')) // TTN V2 HTTPS POST
        {
            $data_array = $this->parse_ttn_payload($request_data);
            $payload_type = 'ttn-v2';
        }
        else if (($request->filled('data') || $request->filled('identifiers'))) // TTN V3 Packet broker HTTPS POST
        {
            $data_array = $this->parse_ttn_payload($request_data['data']);
            $payload_type = 'ttn-v3-pb';
        }
        else if (($request->filled('end_device_ids') || $request->filled('uplink_message'))) // TTN V3 HTTPS POST
        {
            $data_array = $this->parse_ttn_payload($request_data);
            $payload_type = 'ttn-v3';
        }
        $this->cacheRequestRate('store-lora-sensors-'.$payload_type);
        
        //die(print_r([$payload_type, $data_array]));
        //$logFileName = isset($data_array['key']) ? 'lora_sensor_'.$data_array['key'].'.json' : 'lora_sensor_no_key.json';
        //Storage::disk('local')->put('sensors/'.$logFileName, '[{"payload_type":"'.$payload_type.'"},{"request_input":'.json_encode($request_data).'},{"data_array":'.json_encode($data_array).'}]');

        return $this->storeMeasurements($data_array);
    }

   /**
    api/sensors POST
    Store sensor measurement data (see BEEP sensor data API definition) from API, or TTN. In case of using api/unsecure_sensors, this is used for legacy measurement devices that do not have the means to encrypt HTTPS cypher
    @bodyParam key string required DEV EUI of the Device to enable storing sensor data
    @bodyParam data array Measurement data
    @bodyParam payload_fields array TTN Measurement data
    */
    public function storeMeasurementData(Request $request)
    {
        $request_data = $request->input();
        // Check for valid data 
        if (($request->filled('payload_fields') || $request->filled('payload_raw')) && $request->filled('hardware_serial')) // TTN HTTP POST
        {
            $data_array = $this->parse_ttn_payload($request_data);
            $this->cacheRequestRate('store-lora-sensors-ttn');
        }
        else if ($request->filled('LrnDevEui') && $request->filled('DevEUI_uplink.payload_hex')) // KPN/Simpoint
        {
            $data_array = $this->parse_kpn_payload($request_data);
            $this->cacheRequestRate('store-lora-sensors-kpn');
        }
        else if ($request->filled('data')) // Check for sensor string (colon and pipe devided) fw v1-3
        {
            $data_array = $this->convertSensorStringToArray($request_data['data']);
            $this->cacheRequestRate('store-sensors');
        }
        else // Assume post data input
        {
            $data_array = $request_data;
            $this->cacheRequestRate('store-sensors');
        }
        
        //die(print_r($data_array));
        return $this->storeMeasurements($data_array);
    }


    /**
    api/sensors/flashlog
    POST data from BEEP base fw 1.5.0+ FLASH log (with timestamp), interpret data and store in InlfuxDB (overwriting existing data). BEEP base BLE cmd: when the response is 200 OK and erase_mx_flash > -1, provide the ERASE_MX_FLASH BLE command (0x21) to the BEEP base with the last byte being the HEX value of the erase_mx_flash value (0 = 0x00, 1 = 0x01, i.e.0x2100, or 0x2101, i.e. erase_type:"fatfs", or erase_type:"full")
    @authenticated
    @bodyParam id integer Device id to update. (Required without key and hardware_id)
    @bodyParam key string DEV EUI of the sensor to enable storing sensor data incoming on the api/sensors or api/lora_sensors endpoint. (Required without id and hardware_id)
    @bodyParam hardware_id string Hardware id of the device as device name in TTN. (Required without id and key)
    @bodyParam data string MX_FLASH_LOG Hexadecimal string lines (new line) separated, with many rows of log data, or text file binary with all data inside.
    @bodyParam file binary File with MX_FLASH_LOG Hexadecimal string lines (new line) separated, with many rows of log data, or text file binary with all data inside.
    @queryParam show integer 1 for displaying info in result JSON, 0 for not displaying (default).
    @queryParam save integer 1 for saving the data to a file (default), 0 for not save log file.
    @queryParam fill integer 1 for filling data gaps in the database, 0 for not filling gaps (default).
    @queryParam log_size_bytes integer 0x22 decimal result of log size requested from BEEP base.
    @response{
          "lines_received": 20039,
          "bytes_received": 9872346,
          "log_saved": true,
          "log_parsed": false,
          "log_messages":29387823
          "erase_mx_flash": -1,
          "erase":false,
          "erase_type":"fatfs"
        }
    */
    public function flashlog(Request $request)
    {
        $user= $request->user();
        $inp = $request->all();
        $sid = isset($inp['id']) ? $inp['id'] : null;
        $key = null;
        
        if (isset($inp['key']) && !isset($inp['hardware_id']) && !isset($inp['id']) )
        {
            $key = strtolower($inp['key']);
            $dev = $user->devices()->whereRaw('lower(`key`) = \''.$key.'\'')->first(); // check for case insensitive device key before validation
            if ($dev)
            {
                $inp['id'] = $dev->id;
                $sid       = $inp['id'];
            }
        }
        
        $hwi = null;
        if (isset($inp['hardware_id']))
        {
            $hwi = strtolower($inp['hardware_id']);
            $inp['hardware_id'] = $hwi;
        }

        $validator = Validator::make($inp, [
            'id'                => ['required_without_all:key,hardware_id','integer','exists:sensors,id'],
            'hardware_id'       => ['required_without_all:key,id','string','exists:sensors,hardware_id'],
            'key'               => ['required_without_all:id,hardware_id','string','min:4','exists:sensors,key'],
            'data'              => 'required_without:file',
            'file'              => 'required_without:data|file',
            'show'              => 'nullable|boolean',
            'save'              => 'nullable|boolean',
            'fill'              => 'nullable|boolean',
            'log_size_bytes'    => 'nullable|integer'
        ]);

        $result   = null;
        $parsed   = false;
        $saved    = false;
        $files    = false;
        $messages = 0;

        if ($validator->fails())
        {
            return Response::json(['errors'=>$validator->errors()], 400);
        }
        else
        {
            $device    = null;

            if (isset($sid))
            {
                if (Auth::user()->hasRole('superadmin'))
                    $device = Device::find($sid);
                else
                    $device = $user->devices()->where('id', $sid)->first();
            }
            else if (isset($key) && !isset($sid) && isset($hwi))
                $device = $user->devices()->where('hardware_id', $hwi)->where('key', $key)->first();
            else if (isset($hwi))
                $device = $user->devices()->where('hardware_id', $hwi)->first();
            else if (isset($key) && !isset($sid) && !isset($hwi))
                $device = $user->devices()->where('key', $key)->first();
            
            if ($device == null)
                return Response::json(['errors'=>'device_not_found'], 400);

            $log_bytes = $request->filled('log_size_bytes') ? intval($inp['log_size_bytes']) : null;
            $fill      = $request->filled('fill') ? $inp['fill'] : false;
            $show      = $request->filled('show') ? $inp['show'] : false;
            $save      = $request->filled('save') ? $inp['save'] : true;

            if ($device && ($request->filled('data') || $request->hasFile('file')))
            {
                $sid   = $device->id; 
                $time  = date("YmdHis");
                $disk  = env('FLASHLOG_STORAGE', 'public');
                $f_dir = 'flashlog';
                $data  = '';
                $lines = 0; 
                $bytes = 0; 
                $logtm = 0;
                $erase = -1;
                
                if ($request->hasFile('file') && $request->file('file')->isValid())
                {
                    $files= true;
                    $file = $request->file('file');
                    $name = "sensor_".$sid."_flash_$time.log";
                    $f_log= Storage::disk($disk)->putFileAs($f_dir, $file, $name); 
                    $saved= $f_log ? true : false; 
                    $data = Storage::disk($disk)->get($f_dir.'/'.$name);
                    $f_log= Storage::disk($disk)->url($f_dir.'/'.$name); 
                    if ($save == false) // check if file needs to be saved
                    {
                        $saved = Storage::disk($disk)->delete($f_dir.'/'.$name) ? false : true;
                        $f_log = null;
                    }
                }
                else
                {
                    $data = $request->input('data');
                    if ($save)
                    {
                        $logFileName = $f_dir."/sensor_".$sid."_flash_$time.log";
                        $saved = Storage::disk($disk)->put($logFileName, $data);
                        $f_log = Storage::disk($disk)->url($logFileName); 
                    }
                }

                if ($data)
                {
                    $f = [
                        'user_id'       => $user->id,
                        'device_id'     => $device->id,
                        'log_file'      => $f_log,
                        'log_size_bytes'=> $log_bytes,
                        'log_saved'     => $saved
                    ];
                    $flashlog = new FlashLog($f); 
                    $result   = $flashlog->log($data, $log_bytes, $save, $fill, $show); // creates result for erasing flashlog in BEEP base apps 
                    $messages = $result['log_messages'];
                    $lines    = $result['lines_received'];
                    $bytes    = $result['bytes_received'];
                    $logtm    = $result['log_has_timestamps'];
                    $saved    = $result['log_saved'];
                    $parsed   = $result['log_parsed'];
                    $erase    = $result['erase'];
                }

                Webhook::sendNotification("Flashlog from ".$user->name.", device: ".$device->name.", parsed:".$parsed.", size: ".round($bytes/1024/1024, 2)."MB (".($log_bytes != null && $log_bytes > 0 ? round(100*$bytes/$log_bytes, 1) : '?')."%), messages:".$messages.", saved:".$saved.", erased:".$erase.", to disk:".$disk.'/'.$f_dir);
            }

            if ($show)
            {
                $result['fields'] = array_keys($inp);
                //$result['output'] = $out;
            }
        }

        return Response::json($result, $parsed ? 200 : 500);
    }

    public function decode_beep_lora_payload(Request $request, $port, $payload)
    {
        return Response::json($this->decode_beep_payload($payload, $port));
    }

    /**
    api/sensors/measurements GET
    Request all sensor measurements from a certain interval (hour, day, week, month, year) and index (0=until now, 1=previous interval, etc.)
    @authenticated
    @bodyParam key string DEV EUI to look up the sensor (Device)
    @bodyParam id integer ID to look up the sensor (Device)
    @bodyParam hive_id integer Hive ID to look up the sensor (Device)
    @bodyParam names string comma separated list of Measurement abbreviations to filter request data (weight_kg, t, h, etc.)
    @bodyParam interval string Data interval for interpolation of measurement values: hour (2min), day (10min), week (1 hour), month (3 hours), year (1 day). Default: day.
    @bodyParam index integer Interval index (>=0; 0=until now, 1=previous interval, etc.). Default: 0.
    */
    public function data(Request $request)
    {
        $this->cacheRequestRate('get-measurements');

        //Get the sensor
        $device  = $this->get_user_device($request);
        $location= $device->location();
        
        $client = $this->client;
        $first  = $client::query('SELECT * FROM "sensors" WHERE ("key" = \''.$device->key.'\' OR "key" = \''.strtolower($device->key).'\' OR "key" = \''.strtoupper($device->key).'\') ORDER BY time ASC LIMIT 1')->getPoints(); // get first sensor date
        $first_w= [];
        if ($location && isset($location->coordinate_lat) && isset($location->coordinate_lon))
            $first_w = $client::query('SELECT * FROM "weather" WHERE "lat" = \''.$location->coordinate_lat.'\' AND "lon" = \''.$location->coordinate_lon.'\' ORDER BY time ASC LIMIT 1')->getPoints(); // get first weather date
        
        if (count($first) == 0 && count($first_w) == 0)
            return Response::json('sensor-none-error', 500);
        
        $names = array_keys($this->valid_sensors);

        if ($request->filled('names'))
            $names = explode(",", $request->input('names'));

        if (count($names) == 0)
            return Response::json('sensor-none-defined', 500);

        // add sensorDefinition names
        $sensorDefinitions = [];
        foreach ($names as $name)
        {
            $measurement_id   = Measurement::getIdByAbbreviation($name);
            $sensordefinition = $device->sensorDefinitions->where('output_measurement_id', $measurement_id)->sortByDesc('updated_at')->first();
            if ($sensordefinition)
                $sensorDefinitions["$name"] = ['name'=>$sensordefinition->name, 'inside'=>$sensordefinition->inside];
        }

        // select appropriate interval
        $deviceMaxResolutionMinutes = 1;
        if (isset($device->measurement_interval_min))
            $deviceMaxResolutionMinutes = $device->measurement_interval_min * max(1,$device->measurement_transmission_ratio);

        $interval  = $request->input('interval','day');
        $index     = $request->input('index',0);
        $timeGroup = $request->input('timeGroup','day');
        $timeZone  = $request->input('timezone','Europe/Amsterdam');
        
        $durationInterval = $interval.'s';
        $requestInterval  = $interval;
        $resolution       = null;
        $staTimestamp = new Moment();
        $staTimestamp->setTimezone($timeZone);
        $endTimestamp = new Moment();
        $endTimestamp->setTimezone($timeZone);
        // if (timeGroup != null)
        // {
            switch($interval)
            {
                case 'year':
                    $resolution = '1d';
                    $staTimestamp->subtractYears($index);
                    $endTimestamp->subtractYears($index);
                    break;
                case 'month':
                    $resolution = $deviceMaxResolutionMinutes > 180 ? $deviceMaxResolutionMinutes.'m' : '3h';
                    $staTimestamp->subtractMonths($index);
                    $endTimestamp->subtractMonths($index);
                    break;
                case 'week':
                    $requestInterval = 'week';
                    $resolution = $deviceMaxResolutionMinutes > 60 ? $deviceMaxResolutionMinutes.'m' : '1h';
                    $staTimestamp->subtractWeeks($index);
                    $endTimestamp->subtractWeeks($index);
                    break;
                case 'day':
                    $resolution = $deviceMaxResolutionMinutes > 10 ? $deviceMaxResolutionMinutes.'m' : '10m';
                    $staTimestamp->subtractDays($index);
                    $endTimestamp->subtractDays($index);
                    break;
                case 'hour':
                    $resolution = $deviceMaxResolutionMinutes > 2 ? $deviceMaxResolutionMinutes.'m' : '2m';
                    $staTimestamp->subtractHours($index);
                    $endTimestamp->subtractHours($index);
                    break;
            }
        //}
        $staTimestampString = $staTimestamp->startOf($requestInterval)->setTimezone('UTC')->format($this->timeFormat);
        $endTimestampString = $endTimestamp->endOf($requestInterval)->setTimezone('UTC')->format($this->timeFormat);    
        $groupBySelect        = null;
        $groupBySelectWeather = null;
        $groupByResolution  = '';
        $limit              = 'LIMIT '.$this->maxDataPoints;
        $options            = ['precision'=> $this->precision];
        
        if($resolution != null)
        {
            if ($device)
            {
                $groupByResolution = 'GROUP BY time('.$resolution.') fill(null)';
                $queryList         = Device::getAvailableSensorNamesFromData($names, '("key" = \''.$device->key.'\' OR "key" = \''.strtolower($device->key).'\' OR "key" = \''.strtoupper($device->key).'\') AND time >= \''.$staTimestampString.'\' AND time <= \''.$endTimestampString.'\'');

                foreach ($queryList as $i => $name) 
                    $queryList[$i] = 'MEAN("'.$name.'") AS "'.$name.'"';
                
                $groupBySelect = implode(', ', $queryList);
            }
            // Add weather
            if ($location && isset($location->coordinate_lat) && isset($location->coordinate_lon))
            {
                $queryListWeather   = Device::getAvailableSensorNamesFromData($names, '"lat" = \''.$location->coordinate_lat.'\' AND "lon" = \''.$location->coordinate_lon.'\' AND time >= \''.$staTimestampString.'\' AND time <= \''.$endTimestampString.'\'', 'weather');
                
                foreach ($queryListWeather as $i => $name) 
                    $queryListWeather[$i] = 'MEAN("'.$name.'") AS "'.$name.'"';

                $groupBySelectWeather = implode(', ', $queryListWeather);
            }
        }
        
        $sensors_out = [];
        $weather_out = [];
        $old_values  = false;
        
        if ($groupBySelect != null) 
        {
            $sensorQuery = 'SELECT '.$groupBySelect.' FROM "sensors" WHERE ("key" = \''.$device->key.'\' OR "key" = \''.strtolower($device->key).'\' OR "key" = \''.strtoupper($device->key).'\') AND time >= \''.$staTimestampString.'\' AND time <= \''.$endTimestampString.'\' '.$groupByResolution.' '.$limit;
            $result      = $client::query($sensorQuery, $options);
            $sensors_out = $result->getPoints();
        }

        // Add weather data
        if ($groupBySelectWeather != null && $location && isset($location->coordinate_lat) && isset($location->coordinate_lon))
        {
            $weatherQuery = 'SELECT '.$groupBySelectWeather.' FROM "weather" WHERE "lat" = \''.$location->coordinate_lat.'\' AND "lon" = \''.$location->coordinate_lon.'\' AND time >= \''.$staTimestampString.'\' AND time <= \''.$endTimestampString.'\' '.$groupByResolution.' '.$limit;
            $result       = $client::query($weatherQuery, $options);
            $weather_out  = $result->getPoints();

            if ($groupBySelect == null)
            {
                $sensors_out = $weather_out;
            }
            else if (count($weather_out) == count($sensors_out))
            {
                foreach ($sensors_out as $key => $value) 
                {
                    foreach ($weather_out[$key] as $weather_name => $weather_value) 
                    {
                        if ($weather_name != 'time')
                            $sensors_out[$key][$weather_name] =  $weather_value;
                    }
                }
            }
        }

        return Response::json( ['id'=>$device->id, 'interval'=>$interval, 'index'=>$index, 'timeGroup'=>$timeGroup, 'resolution'=>$resolution, 'measurements'=>$sensors_out, 'old_values'=>$old_values, 'sensorDefinitions'=>$sensorDefinitions] );
    }
}