<?php

namespace App\Modules\Ordering\Notifications;

use Illuminate\Notifications\Notification;

class ComplaintRespondedNotification extends Notification
{
    public function __construct(
        public int $koperasiId,
        public int $complaintId,
        public string $subject,
        public string $status,
        public string $adminResponse,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'complaint_responded',
            'koperasi_id' => $this->koperasiId,
            'complaint_id' => $this->complaintId,
            'subject' => $this->subject,
            'status' => $this->status,
            'admin_response' => $this->adminResponse,
            'message' => "Pengaduan \"{$this->subject}\" diperbarui menjadi {$this->status}: {$this->adminResponse}",
        ];
    }
}
