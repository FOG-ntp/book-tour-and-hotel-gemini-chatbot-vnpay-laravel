<?php

namespace App\Http\Controllers\Page\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use App\Http\Requests\LoginRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //$this->middleware('guest')->except('logout');
    }

    public function login()
    {
        if (Auth::guard('users')->check()) {
            return redirect()->back();
        }

        return view('page.auth.login');
    }

    public function postLogin(LoginRequest $request)
    {
        $data = $request->except('_token');
        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return redirect()->back()->with('danger', 'Thông tin tài khoản không tồn tại');
        }

        if($user->status != 1) {
            return redirect()->back()->with('danger', 'Tài khoản của bạn đã bị khóa');
        }

        if (Auth::guard('users')->attempt($data)) {
            $user = Auth::guard('users')->user();
            if ($request->hasCookie('chat_token')) {
                $guestToken = $request->cookie('chat_token');
                \App\Models\ChatMessage::where('guest_token', $guestToken)
                    ->whereNull('user_id')
                    ->update(['user_id' => $user->id, 'guest_token' => null]);
            }
            return redirect()->route('page.home')->withCookie(\Illuminate\Support\Facades\Cookie::forget('chat_token'));
        }
        return redirect()->back()->with('danger', 'Đăng nhập thất bại.');
    }

    public function logout()
    {
        Auth::guard('users')->logout();
        return redirect()->route('page.home');
    }
}
