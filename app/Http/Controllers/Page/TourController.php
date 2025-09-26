<?php

namespace App\Http\Controllers\Page;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Tour;
use App\Models\User;
use App\Models\BookTour;
use App\Http\Requests\BookTourRequest;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Mail;
use App\Models\Payment;


class TourController extends Controller
{
    //
    public function index(Request $request)
    {
        $tours = Tour::with('user');

        if ($request->key_tour) {
            $tours->where('t_title', 'like', '%' . $request->key_tour . '%');
        }

        if ($request->t_start_date) {
            $startDate = date('Y-m-d', strtotime($request->t_start_date));
            $tours->where('t_start_date', '>=', $startDate);
        }

        if ($request->t_end_date) {
            $endDate = date('Y-m-d', strtotime($request->t_end_date));
            $tours->where('t_end_date', '<=', $endDate);
        }

        if ($request->price) {
            $price = explode('-', $request->price);
            $tours->whereBetween('t_price_adults', [$price[0], $price[1]]);
        }

        $tours = $tours->orderBy('t_start_date')->paginate(NUMBER_PAGINATION_PAGE);

        $viewData = [
            'tours' => $tours
        ];
        return view('page.tour.index', $viewData);
    }

    public function detail(Request $request, $id)
    {
        $tour = Tour::with(['comments' => function ($query) use ($id) {
            $query->with(['user', 'replies' => function ($q) {
                $q->with('user')->limit(10);
            }])->where('cm_tour_id', $id)->where('cm_status', '1')->limit(20)->orderByDesc('id');
        }])->find($id);

        if (!$tour) {
            return redirect()->back()->with('error', 'Dữ liệu không tồn tại');
        }
        $tours = Tour::where('t_location_id', $tour->t_location_id)->where('id', '<>', $id)->orderBy('id')->limit(NUMBER_PAGINATION_PAGE)->get();

        return view('page.tour.detail', compact('tour', 'tours'));
    }

    public function bookTour(Request $request, $id, $slug)
    {
        if (!Auth::guard('users')->check()) {
            return redirect()->back()->with('error', 'Vui lòng đăng nhập để đặt tour');
        }
        $tour = Tour::find($id);

        if (!$tour) {
            return redirect()->back()->with('error', 'Dữ liệu không tồn tại');
        }
        $user =  User::find(Auth::guard('users')->user()->id);

        return view('page.tour.book', compact('tour', 'user'));
    }

    public function postBookTour(BookTourRequest $request, $id)
    {
        $tour = Tour::find($id);
        $numberUser = $request->b_number_adults + $request->b_number_children + $request->b_number_child6 + $request->b_number_child2;
        if (($tour->t_number_registered + $numberUser) > $tour->t_number_guests) {
            return redirect()->back()->with('error', 'Số lượng người đăng ký đã vượt quá giới hạn');
        }

        \DB::beginTransaction();
        try {
            $params = $request->except(['_token']);
            $user = Auth::guard('users')->user();
            $params['b_tour_id'] = $id;
            $params['b_user_id'] = $user->id;
            $params['b_status'] = 1;
            $params['b_price_adults']= $tour->t_price_adults -( $tour->t_price_adults* $tour->t_sale/100);
            $params['b_price_children']=$tour->t_price_children -( $tour->t_price_children* $tour->t_sale/100); 
            $params['b_price_child6']=($tour->t_price_children -( $tour->t_price_children* $tour->t_sale/100))*50/100;
            $params['b_price_child2']=($tour->t_price_children -( $tour->t_price_children* $tour->t_sale/100))*25/100;

            $priceAdults = ($tour->t_price_adults -( $tour->t_price_adults* $tour->t_sale/100)) * $params['b_number_adults'];
            $priceChildren = ($tour->t_price_children -( $tour->t_price_children* $tour->t_sale/100)) * $params['b_number_children']; 
            $priceChild6 = (($tour->t_price_children -( $tour->t_price_children* $tour->t_sale/100))*50/100) * $params['b_number_child6'];
            $priceChild2 = (($tour->t_price_children -( $tour->t_price_children* $tour->t_sale/100))*25/100) * $params['b_number_child2'];
            // dd($priceChild6);
            // $params['b_price_adults'] = ($tour->t_price_adults - ($tour->t_price_adults * $tour->t_sale / 100)) * $params['b_number_adults'];
            // $params['b_price_children'] = ($tour->t_price_children - ($tour->t_price_children * $tour->t_sale / 100)) * $params['b_number_children'];
            // $params['b_price_child6'] = (($tour->t_price_children - ($tour->t_price_children * $tour->t_sale / 100)) * 50 / 100) * $params['b_number_child6'];
            // $params['b_price_child2'] = (($tour->t_price_children - ($tour->t_price_children * $tour->t_sale / 100)) * 25 / 100) * $params['b_number_child2'];
            $params['b_total_money']  =  $priceAdults + $priceChildren + $priceChild6 + $priceChild2;
            $Total = $params['b_total_money'];
            // dd($params['b_total_money']);
            $book = BookTour::create($params);
            if ($book) {
                $tour->t_follow = $tour->t_follow + $numberUser;
                $tour->save();
            }
            \DB::commit();

            if($request->submit =='Thanh toán online'){
                session(['TotalMoney' => $Total]);
                return redirect()->route('get.from.payment',$book->id);
            }
            // $mail = $user->email;
            // Mail::send('emailtn', compact('book', 'tour', 'user'), function ($email) use ($mail) {
            //     $email->subject('Thông tin xác nhận đơn Booking');
            //     $email->to($mail);
            // });
            return redirect()->route('page.home')->with('success', 'Cám ơn bạn đã đặt tour chúng tôi sẽ liên hệ sớm để xác nhận.');
        } catch (\Exception $exception) {
            \DB::rollBack();
            return redirect()->back()->with('error', 'Đã xảy ra lỗi khi lưu dữ liệu');
        }
    }

    public function getFromPayMent($id){
        $book = BookTour::find($id);
        $totalMoney = session('TotalMoney');
       
        
        if(!$book){
            return redirect()->back()->with('danger','Đã xảy ra lỗi không thể thanh toán online');
        }   
        session(['book_id'=>$book->id]);
        return view('page.vnpay.index',compact('book','totalMoney'));
    }

    public function createPayMent(Request $request){
        $totalMoney = $request->amount;
        $vnp_TxnRef = randString(15);
        $vnp_OrderInfo = $request->order_desc;
        $vnp_OrderType = $request->order_type;
        $vnp_Amount = $totalMoney * 100;
        $vnp_Locale = $request->language;
        $vnp_BankCode = $request->bank_code;
        $vnp_IpAddr = $_SERVER['REMOTE_ADDR'];

        $inputData = array(
            "vnp_Version" => "2.0.0",
            "vnp_TmnCode" => env('VNP_TMN_CODE'),
            "vnp_Amount" => $vnp_Amount,
            "vnp_Command" => "pay",
            "vnp_CreateDate" => date('YmdHis'),
            "vnp_CurrCode" => "VND",
            "vnp_IpAddr" => $vnp_IpAddr,
            "vnp_Locale" => $vnp_Locale,
            "vnp_OrderInfo" => $vnp_OrderInfo,
            "vnp_OrderType" => $vnp_OrderType,
            "vnp_ReturnUrl" => route('vnpay.return'),
            "vnp_TxnRef" => $vnp_TxnRef,
        );

        if (isset($vnp_BankCode) && $vnp_BankCode != "") {
            $inputData['vnp_BankCode'] = $vnp_BankCode;
        }
        ksort($inputData);

        $query = "";
        $i = 0;
        $hashdata = "";
        foreach ($inputData as $key => $value) {
            if ($i == 1) {
                $hashdata .= '&' . $key . "=" . $value;
            } else {
                $hashdata .= $key . "=" . $value;
                $i = 1;
            }
            $query .= urlencode($key) . "=" . urlencode($value) . '&';
        }

        $vnp_Url = env('VNP_URL') . "?" . $query;
        if (env('VNP_HASH_SECRET')) {
            $vnpSecureHash = hash('sha256', env('VNP_HASH_SECRET') . $hashdata);
            $vnp_Url .= 'vnp_SecureHashType=SHA256&vnp_SecureHash=' . $vnpSecureHash;
        }

        return redirect($vnp_Url);
    }

    public function vnPayReturn(Request $request){
        if(session()->has('book_id') && $request->vnp_ResponseCode == '00'){
            \DB::beginTransaction();
            try{
                $vnpayData = $request->all();
                $bookId = session()->get('book_id');
                $book = BookTour::find($bookId);
                if(!$book){
                    return redirect()->back()->with('danger','Đã xảy ra lỗi không thể thanh toán online');
                }
                $dataPayment = [
                    'p_book_tour_id' =>$book->id,
                    'p_transaction_code' =>$vnpayData['vnp_TxnRef'],
                    'p_money'   =>$book->b_total_money,
                    'p_note'    =>$vnpayData['vnp_OrderInfo'],
                    'p_vnp_response_code' => $vnpayData['vnp_ResponseCode'],
                    'p_code_vnpay' =>$vnpayData['vnp_TransactionNo'],
                    'p_code_bank' =>$vnpayData['vnp_BankCode'],
                    'p_time' => date('Y-m-d H:i', strtotime($vnpayData['vnp_PayDate'])),
                    'created_at' =>Carbon::now(),
                    'updated_at' =>Carbon::now(),
                ];
                if(Auth::guard('users')->check()){
                    $user = Auth::guard('users')->user();
                    $dataPayment['p_user_id'] = $user->id;
                }
                Payment::insert($dataPayment);
                $book->b_status=3;
                $book->save();

                \DB::commit();
                return view('page.vnpay.vnpay_return',compact('vnpayData'));
            }
            catch(Exception $ex){
                dd($ex);
                \DB::rollback();
                return redirect()->route('page.home')->with('error','Đặt hàng không thành công');
            }
        }else{
            return redirect()->route('page.home')->with('error','Thanh toán không thành công');
        }
    }

    public function loi()
    {
        return redirect()->back()->with('error', 'Số lượng người đăng ký đã vượt quá giới hạn');
    }
}
