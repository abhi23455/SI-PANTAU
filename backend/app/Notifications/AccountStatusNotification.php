<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $status) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->status === 'active';
        $message = (new MailMessage)
            ->subject($approved ? 'Pendaftaran SIPANTAU Disetujui' : 'Pendaftaran SIPANTAU Ditolak')
            ->greeting('Halo '.$notifiable->full_name.',');

        if ($approved) {
            return $message
                ->line('Pendaftaran akun SIPANTAU Anda telah disetujui oleh administrator.')
                ->action('Masuk ke SIPANTAU', rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/').'/');
        }

        return $message
            ->line('Mohon maaf, pendaftaran akun SIPANTAU Anda belum dapat disetujui.')
            ->line('Silakan masuk ke SIPANTAU untuk memeriksa dan mengajukan ulang data profil Anda.');
    }
}
