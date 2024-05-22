<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Models\User;
use App\Jobs\NotifyViaMqtt;
use App\Models\Admin\Driver;
use Illuminate\Http\Request;
use App\Jobs\NotifyViaSocket;
use App\Models\Request\RequestMeta;
use App\Base\Constants\Masters\PushEnums;
use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Request\Request as RequestModel;
use App\Http\Requests\Request\AcceptRejectRequest;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Models\Request\DriverRejectedRequest;
use Kreait\Firebase\Contract\Database;
use App\Jobs\Notifications\SendPushNotification;
use Sk\Geohash\Geohash;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;



/**
 * @group Driver-trips-apis
 *
 * APIs for Driver-trips apis
 */
class RequestAcceptRejectController extends BaseController
{
    protected $request;

    public function __construct(RequestModel $request,Database $database)
    {
        $this->request = $request;
        $this->database = $database;
    }

    /**
    * Driver Response for Trip Request
    * @bodyParam request_id uuid required id request
    * @bodyParam is_accept boolean required response of request i.e accept or reject. input should be 0 or 1.
    * @response {
    "success": true,
    "message": "success"}
    */
    public function respondRequest(AcceptRejectRequest $request)
    {
        /**
        * Get Request Detail
        * Validate the request i,e the request is already accepted by some one and it is a valid request for accept or reject state.
        * If is_accept is true then update the driver's id to the request detail.
        * And Update the driver's available state as false. And delete all meta driver records from request_meta table
        * Send the notification to the user with request detail.
        * If is_accept is false, then delete the driver record from request_meta table
        * And Send the request to next driver who is available in the request_meta table
        * If there is no driver available in request_meta table, then send notification with no driver available state to the user.
        */
        // Get Request Detail
        $request_detail = $this->request->where('id', $request->input('request_id'))->first();
        // Validate the request i,e the request is already accepted by some one and it is a valid request for accept or reject state.
        $this->validateRequestDetail($request_detail);
        $driver = auth()->user()->driver;

        // Delete Meta Driver From Firebase
        // $this->database->getReference('request-meta/'.$request_detail->id)->set(['driver_id'=>'','request_id'=>$request_detail->id,'user_id'=>$request_detail->user_id,'active'=>1,'updated_at'=> Database::SERVER_TIMESTAMP]);

        // $this->database->getReference('request-meta/'.$request_detail->id.'/'.$driver->id)->remove();

        if ($request->input('is_accept')) {

            $this->database->getReference('request-meta/'.$request_detail->id)->remove();

            // Update Driver to the trip request detail
            $updated_params = ['driver_id'=>auth()->user()->driver->id,
            'accepted_at'=>date('Y-m-d H:i:s'),
            'is_driver_started'=>true];

            if(auth()->user()->driver->owner_id){

                $updated_params['owner_id'] = auth()->user()->driver->owner_id;

                $updated_params['fleet_id'] = auth()->user()->driver->fleet_id;
            }

            $request_detail->update($updated_params);
            $request_detail->fresh();
            // Delete all Meta records of the request
            $this->deleteMetaRecords($request);
            // Update the driver's available state as false
            $driver->available = false;
            $driver->save();
            $request_result =  fractal($request_detail, new TripRequestTransformer);
            $push_request_detail = $request_result->toJson();
            if ($request_detail->if_dispatch) {
                goto accet_dispatch_notify;
            }
            $user = User::find($request_detail->user_id);
            $title = trans('push_notifications.trip_accepted_title');
            $body = trans('push_notifications.trip_accepted_body');
            $push_data = ['notification_enum'=>PushEnums::TRIP_ACCEPTED_BY_DRIVER,'result'=>(string)$push_request_detail];
            dispatch(new SendPushNotification($user,$title,$body));

             accet_dispatch_notify:
        // @TODO send sms,email & push notification with request detail
        } else {

            $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');
            // Save Driver Reject Requests
            DriverRejectedRequest::create(['request_id'=>$request_detail->id,
                'driver_id'=>$driver->id]);

            $push_request_detail = $request_result->toJson();
            // Delete Driver record from meta table
            RequestMeta::where('request_id', $request->input('request_id'))->where('driver_id', $driver->id)->delete();
            
            // Send request to next driver
            $request_meta = RequestMeta::where('request_id', $request->input('request_id'))->first();
            if ($request_meta) {
                $request_meta->update(['active'=>true]);
                // @TODO Send push notification like create request to the driver
                $title = trans('push_notifications.new_request_title');
                $body = trans('push_notifications.new_request_body');
                $push_data = ['notification_enum'=>PushEnums::REQUEST_CREATED,'result'=>(string)$push_request_detail];
                $driver = Driver::find($request_meta->driver_id);

                // Add Next Driver into Firebase Request Meta
                $this->database->getReference('request-meta/'.$request_detail->id)->set(['driver_id'=>$request_meta->driver_id,'request_id'=>$request_detail->id,'user_id'=>$request_detail->user_id,'active'=>1,'updated_at'=> Database::SERVER_TIMESTAMP]);

                $notifiable_driver = $driver->user;
                dispatch(new SendPushNotification($notifiable_driver,$title,$body));;

                
            } else {

                 $nearest_drivers =  $this->getFirebaseDrivers($request_detail);

        $request_detail->fresh();
        
        if($request_detail->is_cancelled){

            goto end;
        }

        $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');

         if (!$nearest_drivers) {
                goto no_drivers_available;
        }

         $selected_drivers = [];
        $i = 0;
        foreach ($nearest_drivers as $driver) {
            // Log::info("in-loop");
            // $selected_drivers[$i]["request_id"] = $request_detail->id;
            $selected_drivers[$i]["user_id"] = $request_detail->userDetail->id;
            $selected_drivers[$i]["driver_id"] = $driver->id;
            $selected_drivers[$i]["active"] = $i == 0 ? 1 : 0;
            $selected_drivers[$i]["assign_method"] = 1;
            $selected_drivers[$i]["created_at"] = date('Y-m-d H:i:s');
            $selected_drivers[$i]["updated_at"] = date('Y-m-d H:i:s');
            $i++;
        }


        // Send notification to the very first driver
        $first_meta_driver = $selected_drivers[0]['driver_id'];

        // Add first Driver into Firebase Request Meta
        $this->database->getReference('request-meta/'.$request_detail->id)->set(['driver_id'=>$first_meta_driver,'request_id'=>$request_detail->id,'user_id'=>$request_detail->user_id,'active'=>1,'updated_at'=> Database::SERVER_TIMESTAMP]);


        $pus_request_detail = $request_result->toJson();
        $push_data = ['notification_enum'=>PushEnums::REQUEST_CREATED,'result'=>$pus_request_detail];


        $socket_data = new \stdClass();
        $socket_data->success = true;
        $socket_data->success_message  = PushEnums::REQUEST_CREATED;
        $socket_data->result = $request_result;

        $driver = Driver::find($first_meta_driver);

        $notifable_driver = $driver->user;

        $title = trans('push_notifications.new_request_title',[],$notifable_driver->lang);
        $body = trans('push_notifications.new_request_body',[],$notifable_driver->lang);

        dispatch(new SendPushNotification($notifable_driver,$title,$body));

            $request_detail->requestMeta()->create($selected_drivers[0]);

                goto end;

                // Cancell the request as automatic cancell state
                $request_detail->update(['is_cancelled'=>true,'cancel_method'=>0,'cancelled_at'=>date('Y-m-d H:i:s')]);
                $request_result =  fractal($request_detail, new TripRequestTransformer);
                $push_request_detail = $request_result->toJson();
                // Send push notification as no-driver-found to the user
                if ($request_detail->if_dispatch) {
                    goto dispatch_notify;
                }
                $user = User::find($request_detail->user_id);
                $title = trans('push_notifications.no_driver_found_title');
                $body = trans('push_notifications.no_driver_found_body');
                dispatch(new SendPushNotification($user,$title,$body));
                $push_data = ['notification_enum'=>PushEnums::NO_DRIVER_FOUND,'result'=>(string)$push_request_detail];
                dispatch_notify:
                no_drivers_available:
            }
        }
        end:

        return $this->respondSuccess();
    }

    /**
    * Delete All Meta driver's records
    */
    public function deleteMetaRecords(Request $request)
    {
        RequestMeta::where('request_id', $request->input('request_id'))->delete();
    }

    /**
    * Validate the request detail
    */
    public function validateRequestDetail($request_detail)
    {
        if ($request_detail->is_driver_started && $request_detail->driver_id!=auth()->user()->driver->id) {
            $this->throwCustomException('request accepted by another driver');
        }

        if ($request_detail->is_completed) {
            $this->throwCustomException('request completed already');
        }
        if ($request_detail->is_cancelled) {
            $this->throwCustomException('request cancelled');
        }
    }


    /**
     * Find Drivers from Firebase
     * 
     * */

    /**
    * Get Drivers from firebase
    */
    public function getFirebaseDrivers($request_detail)
    {
        $pick_lat = $request_detail->pick_lat;
        $pick_lng = $request_detail->pick_lng;

        $type_id = $request_detail->zoneType->type_id;

        // NEW flow        
        $driver_search_radius = get_settings('driver_search_radius')?:30;

        $radius = kilometer_to_miles($driver_search_radius);

        $calculatable_radius = ($radius/2);

        $calulatable_lat = 0.0144927536231884 * $calculatable_radius;
        $calulatable_long = 0.0181818181818182 * $calculatable_radius;

        $lower_lat = ($pick_lat - $calulatable_lat);
        $lower_long = ($pick_lng - $calulatable_long);

        $higher_lat = ($pick_lat + $calulatable_lat);
        $higher_long = ($pick_lng + $calulatable_long);

        $g = new Geohash();

        $lower_hash = $g->encode($lower_lat,$lower_long, 12);
        $higher_hash = $g->encode($higher_lat,$higher_long, 12);

        $conditional_timestamp = Carbon::now()->subMinutes(7)->timestamp;

        $vehicle_type = $type_id;

        $rejected_driver_ids = DriverRejectedRequest::where('request_id',$request_detail->id)->pluck('driver_id')->toArray();

        $fire_drivers = $this->database->getReference('drivers')->orderByChild('g')->startAt($lower_hash)->endAt($higher_hash)->getValue();
        
        $firebase_drivers = [];

        $i=-1;
    

        foreach ($fire_drivers as $key => $fire_driver) {
            $i +=1; 
            $driver_updated_at = Carbon::createFromTimestamp($fire_driver['updated_at'] / 1000)->timestamp;


            if(array_key_exists('vehicle_type',$fire_driver) && $fire_driver['vehicle_type']==$vehicle_type && $fire_driver['is_active']==1 && $fire_driver['is_available']==1 && $conditional_timestamp < $driver_updated_at){


                $distance = distance_between_two_coordinates($pick_lat,$pick_lng,$fire_driver['l'][0],$fire_driver['l'][1],'K');

                if($distance <= $driver_search_radius){

                    $firebase_drivers[$fire_driver['id']]['distance']= $distance;

                }

            }elseif(array_key_exists('vehicle_types',$fire_driver)  && in_array($vehicle_type, $fire_driver['vehicle_types']) && $fire_driver['is_active']==1 && $fire_driver['is_available']==1 && $conditional_timestamp < $driver_updated_at)
                {

                Log::info("its coming in new loop");

                $distance = distance_between_two_coordinates($pick_lat,$pick_lng,$fire_driver['l'][0],$fire_driver['l'][1],'K');

                if($distance <= $driver_search_radius){

                    $firebase_drivers[$fire_driver['id']]['distance']= $distance;

                }

            }      

        }
        $current_date = Carbon::now();

        asort($firebase_drivers);


        if (!empty($firebase_drivers)) {

            $nearest_driver_ids = [];

            $removable_driver_ids=[];

                foreach ($firebase_drivers as $key => $firebase_driver) {
                    
                    $nearest_driver_ids[]=$key;


                $has_enabled_my_route_drivers=Driver::where('id',$key)->where('active', 1)->where('approve', 1)->where('available', 1)->where(function($query)use($request_detail){
                    $query->where('transport_type','taxi')->orWhere('transport_type','both');
                })->where('enable_my_route_booking',1)->first();


                $route_coordinates=null;

                if($has_enabled_my_route_drivers){

                    //get line string from helper
                    $route_coordinates = get_line_string($pick_lat, $pick_lng, $drop_lat, $drop_lng);

                }       
                        if($has_enabled_my_route_drivers!=null &$route_coordinates!=null){

                            $enabled_route_matched = $nearest_driver->intersects('route_coordinates',$route_coordinates)->first();
                            
                            if(!$enabled_route_matched){

                                $removable_driver_ids[]=$key;
                            }

                            $current_location_of_driver = $nearest_driver->enabledRoutes()->whereDate('created_at',$current_date)->orderBy('created_at','desc')->first();

                            if($current_location_of_driver){

                            $distance_between_current_location_to_drop = distance_between_two_coordinates($current_location_of_driver->current_lat, $current_location_of_driver->current_lng, $drop_lat, $drop_lng,'K');

                            $distance_between_current_location_to_my_route = distance_between_two_coordinates($current_location_of_driver->current_lat, $current_location_of_driver->current_lng, $nearest_driver->my_route_lat, $nearest_driver->my_route_lng,'K');

                            // Difference between both of above values

                            $difference = $distance_between_current_location_to_drop - $distance_between_current_location_to_my_route;

                            $difference=$difference < 0 ? (-1) * $difference : $difference;

                            if($difference>5){

                                $removable_driver_ids[]=$key;

                            }
    
                            }
                            
                        }


                }

            $nearest_driver_ids = array_diff($nearest_driver_ids,$removable_driver_ids);
            $nearest_driver_ids = array_diff($nearest_driver_ids,$rejected_driver_ids);

                if(count($nearest_driver_ids)>0){
                    $nearest_driver_ids[0]=$nearest_driver_ids[0];

                }else{

                   $nearest_driver_ids=[];

                }

                $driver_search_radius = get_settings('driver_search_radius')?:30;

                $haversine = "(6371 * acos(cos(radians($pick_lat)) * cos(radians(pick_lat)) * cos(radians(pick_lng) - radians($pick_lng)) + sin(radians($pick_lat)) * sin(radians(pick_lat))))";
                // Get Drivers who are all going to accept or reject the some request that nears the user's current location.
                $meta_drivers = RequestMeta::whereHas('request.requestPlace', function ($query) use ($haversine,$driver_search_radius) {
                    $query->select('request_places.*')->selectRaw("{$haversine} AS distance")
                ->whereRaw("{$haversine} < ?", [$driver_search_radius]);
                })->pluck('driver_id')->toArray();



                $nearest_drivers = Driver::where('active', 1)->where('approve', 1)->where('available', 1)->where(function($query)use($request_detail){
                    $query->where('transport_type','taxi')->orWhere('transport_type','both');
                })->whereIn('id', $nearest_driver_ids)->whereNotIn('id', $meta_drivers)->get();

          

                if ($nearest_drivers->isEmpty()) {
                    // $this->throwCustomException('all drivers are busy');

                    return null;
                    // return ['no-drivers-found','no-firebase-drivers'];

                }
                // $returned_drivers = [$nearest_drivers,$firebase_drivers];
                
                return $nearest_drivers;
            
        } else {

            // return ['no-drivers-found','no-firebase-drivers'];

            return null;
        }
    }
}
