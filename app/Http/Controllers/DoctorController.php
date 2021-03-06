<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Auth;
use App\User;
use Illuminate\Support\Facades\Hash;
use App\Events\NotifyPatient;
use App\Events\AlertCall;
use App\Events\EndVideo;
use App\Consultations;
use OpenTok\OpenTok;
use OpenTok\MediaMode;
use OpenTok\ArchiveMode;
use DB;
use Session;
use App\DoctorDetails;


class DoctorController extends Controller
{
    //
    public function loginPage()
    {   
        if(Auth::check())
        {
            return redirect()->route('pickup');
        }

    	return view('doctor.login');
    }

    public function login(Request $request)
    {
    	$user = User::where('email',$request->username)->where('enable',1)->first();
        $check = User::where('email',$request->username)->where('enable',0)->first();
    	if($user)
    	{
    		if(Hash::check($request->password, $user->password))
    		{
    			Auth::login($user);
    			return redirect()->route('pickup');
    		}
    		else
    			return redirect()->back()->with('success','Wrong Credentials');
    	}
    	else
        {
            if($check)
            {
                return redirect()->back()->with('success','Account Disabled');
            }
            else
            {
                return redirect()->back()->with('success','Wrong Credentials');
            }

    		
        }
    }

    public function callPickup()
    {
        return view('doctor.call_pickup');
    }

    public function alertPatient(Request $request)
    {
        $id = Auth::user()->id;
        // $text = request()->text;
        $check = Consultations::where('patientId',$request->patientid)->where('doctorId',null)->where('completed',null)->first();
        if($check)
        {   

            $location = geoip($ip = $request->ip());
            Consultations::where('patientId',$request->patientid)->where('completed',null)->update(['doctorId'=>$id,'doctor_location'=>$location->city]);
            event(new NotifyPatient($id,$request->patientid));

                $response = array(
                    'status' => 'success',
                );

                return response()->json($response);
        }
        else
        {
            $response = array(
                    'status' => 'fail',
                );

                return response()->json($response);
        }
        


          
    }

    public function getDetails(Request $request)
    {   
        Consultations::where('doctorId',$request->doctorid)->where('completed',null)->update(['wait_time'=>$request->wait_time]);

        $name = User::where('id',$request->doctorid)->value('name');
        $avatar = User::where('id',$request->doctorid)->value('avatar');

        $response = array(
                    'image' => $avatar,
                    'name' => $name,
                );

        return response()->json($response);

    }

    public function connectVideo(Request $request)
    {
        $api = '46765912';
        $secret = 'd3de7b6811f3603e5c78208af0906c58a0658f53';
        $opentok = new OpenTok($api, $secret);

        $consultation = Consultations::where('doctorId',Auth::user()->id)->where('completed',null)->where('session_id',null)->first();

        if($consultation)
        {
            $sessionOptions = array(
            'archiveMode' => ArchiveMode::ALWAYS,
            'mediaMode' => MediaMode::ROUTED
            );
            $session = $opentok->createSession($sessionOptions);

            $sessionId = $session->getSessionId();

            Consultations::where('doctorId',Auth::user()->id)->where('session_id',null)->where('completed',null)->update(['session_id'=>$sessionId]);
        }
        else
        {
            $sessionId = Consultations::where('doctorId',Auth::user()->id)->where('completed',null)->value('session_id');
        }

        $token = $opentok->generateToken($sessionId);

        return view('doctor_video_call',['session_id'=>$sessionId,'opentok_token'=>$token]);
    }

    public function end(Request $request)
    {   
        $id = Consultations::where('doctorId',Auth::user()->id)->where('completed',null)->value('id');

        $patientid = Consultations::where('doctorId',Auth::user()->id)->where('completed',null)->value('patientId');

        $variable = DB::table('messages')->where('from_id',Auth::user()->id)->orWhere('to_id',Auth::user()->id)->get();
        foreach ($variable as $value) {
            # code...
            $value->consultation_id = $id;
            DB::table('history_messages')->insert(get_object_vars($value));
        }

        DB::table('messages')->where('from_id',Auth::user()->id)->orWhere('to_id',Auth::user()->id)->delete();
        Consultations::where('doctorId',Auth::user()->id)->where('completed',null)->update(['completed'=>'1']);

        User::where('id',$patientid)->decrement('credits');
        $response = array(
                    'success' => 'success',
                );
        $alert = 'end';
        $endid = Auth::user()->id;
        event(new AlertCall($alert,$endid));

        return response()->json($response);           
    }

    public function videoCallAlert(Request $request)
    {   
        $alert = 'alert';
        $id = Auth::user()->id;
        event(new AlertCall($alert,$id));
        $response = array(
                    'success' => 'success',
                );

        return response()->json($response);  
    }

    public function endvideo(Request $request)
    {
        $id = Consultations::where('doctorId',Auth::user()->id)->where('completed',null)->value('patientId');
        event(new EndVideo($id));
        $response = array(
                    'success' => 'success',
                );

        return response()->json($response);
    }

    public function profile()
    {   $id = Auth::user()->id;
        $name = Auth::user()->name;
        $work = DoctorDetails::where('doctor_id',$id)->value('current_hospital');
        $speciality = DoctorDetails::where('doctor_id',$id)->value('speciality');
        $years = DoctorDetails::where('doctor_id',$id)->value('years');
        $doctorprofile = 1;
        return view('doctor.profile',['name'=>$name,'years'=>$years,'work'=>$work,'speciality'=>$speciality,'doctorprofile'=>$doctorprofile]);
    }

    public function updateProfile(Request $request)
    {
        $id = Auth::user()->id;
        User::where('id',$id)->update(['name'=>$request->fullname]);
        DoctorDetails::where('doctor_id',$id)->update(['current_hospital'=>$request->work,'years'=>$request->years,'speciality'=>$request->speciality]);
        return redirect()->back()->with('success', 'Thank You For Contacting Us. We will contact you soon.');
    }

    public function logout()
    {
        Auth::logout();
        Session::flush();
        return redirect()->route('doctorlogin');
    }

    public function autocheck(Request $request)
    {   
        $check = Consultations::where('doctorId',null)->where('completed',null)->where('requested_doctor',Auth::user()->id)->first();

        if($check)
        {
            $id = $check['patientId'];
            $response = array(
                    'success' => 'success',
                    'id'    => $id,
                );

        return response()->json($response);

        }

        $check = Consultations::where('doctorId',null)->where('completed',null)->where('requested_doctor',null)->first();

        if($check)
        {
            $id = $check['patientId'];
            $response = array(
                    'success' => 'success',
                    'id'    => $id,
                );

        return response()->json($response);

        }

        $response = array(
                    'success' => 'fail',
                );

        return response()->json($response);
    }
}
