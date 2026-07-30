<?php

namespace App\Notifications\Partner;

use App\Models\RestaurantDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public RestaurantDocument $document) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Document needs attention')
            ->line('A document ('.$this->document->document_type.') was rejected.')
            ->line($this->document->verification_notes ?: 'Please upload a replacement.');
    }
}
