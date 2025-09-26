<?php
namespace App\Http\Controllers;
use BotMan\BotMan\BotMan;
use Illuminate\Http\Request;
use BotMan\BotMan\Messages\Incoming\Answer;
class BotManController extends Controller
{
    /**
     * Handle the incoming messages from the Botman chatbot.
     */
    public function handle()
    {
     $botman = app('botman');
        // Listen for any message
     $botman->hears('{message}', function($botman, $message) {
      // Convert the message to lowercase to handle case insensitivity
            $message = strtolower($message);
            // If the user says 'hi', start a conversation to ask for their name
      if ($message == 'hi') {
       $this->askName($botman);
      }
            // For any other input, send a default message
      else {
       $botman->reply("Start a conversation by saying hi.");
      }
     });
     $botman->listen();
    }
    /**
     * Ask the user for their name when they say 'hi'.
     */
    public function askName($botman)
    {
     // For fewer questions, you can use the inline conversation approach as shown below. Alternatively, use a dedicated conversation class for multi-step conversations
     $botman->ask('Hello! What is your name?', function(Answer $answer, $conversation) {
            // Capture the user's answer
      $name = $answer->getText();
            // Respond with a personalized message
      $this->say('Nice to meet you, ' . $name);
      //Continue inline conversation.
      $conversation->ask('Can you advise about your email address.', function(Answer $answer, $conversation){
       $email = $answer->getText();
       $this->say('Email : '.$email);
      });
     });
    }
}