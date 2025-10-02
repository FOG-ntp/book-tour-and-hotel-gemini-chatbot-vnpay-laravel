<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GeminiConsultationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\ChatMessage;
use App\Models\Product;//thay thành model tour
use Illuminate\Http\Controller;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Env;
use Exception;
use Str;

class ChatbotController extends Controller
{
    //Get history (user or guest)
    public function fetchMessages(Request $request)
    {
        if (Auth::check()) {
            $msgs = ChatMessage::where('user_id', Auth::id())->orderBy('created_at')->get();
        } else {
            $token = $request->cookie('chat_token');
            $msgs = $token ? ChatMessage::where('guest_token', $token)->orderBy('created_at')->get() : collect();
        }
        return response()->json($msgs);
    }

    //Send message
    public function sendMessages(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $userId = Auth::Id();

        // --- Handle guest token (cookie) ---
        $guestToken = null;
        if (!$userId) {
            $guestToken = $request->cookie('chat_token');
            if (!$guestToken) {
                $guestToken = 'guest_' . Str::random(32);
                cookie()->queue(cookie('chat_token', $guestToken, 60*24*180));
            }
        }

        // 1) save message user to BD
        $userMsg = ChatMessage::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : $guestToken,
            'sender' => 'user',
            'message' => $request->message,
        ]);

        // 2) Prepare prompt
        $products = Produc::where('stock', '>', 0)->get(['name','price','unit','description'])->map(function($p){
            return "{$p->name} - {$p->price} / {$p->unit}";
        })->toArray();
        $productList = implode("\n", $product);

        $prompt = "Bạn là trợ lý bán hàng cho website rau củ. Dưới đây là danh sách một số sản phẩm hiện có: \n$productList\n
                    Hãy trả lời ngắn gọn, trung thực, chỉ dùng thông tin trong danh sách nếu cần.";
        
        //Get history lasted (Exp: 6 msg ~ 3 turns user-bot)
        $history = ChatMessage::query()->where(function($q) use ($userId, $guestToken) {
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                $q->where('guest_token', $guestToken);
            }
        })
        ->lastest()
        ->limit(6)
        ->orderBy('created_at','asc')
        ->get();

        // Change history to suit with firmat Gemini
        $content = [];
        foreach ($history as $msg) {
            $content[] = [
                "role" => $msg->sender === 'user' ? "user" : "model",
                "parts" => [["text" => $msg->message]]
            ];
        }

        //Append new message of user
        $content[] = [
            "role" => "user",
            "parts" => [["text" => $request->message]]
        ];

        // 3) Call AI (Gemini) - If haven't GOOGLE_API_KEY return fallabck text
        $aiReplyText = "Xin lỗi, hiện tại AI chưa được cấu hình";
        if (env('GEMINI_API_KEY')) {
            try {
                $url_apikey = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
                $payload = [
                    "systemInstruction" => [
                        "parts" =>[
                            ["text" => $prompt]
                        ]
                    ],
                    "contents"=> $contents
                ];

                //Call API Gemini
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Google-Api-Key' => env('GEMINI_API_KEY')
                ])->post($url_apikey, $payload);

                if ($response->successful()){
                    $data = $response->json();
                    $aiReplyText = $data['candidates'][0]['content']['parts'][0]['text']??"Xin lỗi, ttôi chưa hiểu câu hỏi.";
                } else {
                    $aiReplyText = "Xin lỗi, hiện tại AI không thể xử lý.";
                    \Log::error('AI API error', ['response'=> $response->json()]);
                }
                
            } catch (\Throwable $e) {
                \Log::error('AI call error: ' . $e->getMessage());
                $aiReplyText = "Xin lỗi, hiện tại AI chưa được cấu hình";
            }
        }

        //4) save bot reply
        $botMsg = ChatMessage::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : $guestToken,
            'sender' => 'bot',
            'message' => $aiReplyText,
        ]);

        //5) Return 2 message created (client append)
        return response()->json([
            'user'=>$userMsg,
            'bot'=>$botMsg,
        ]);
    }
}
