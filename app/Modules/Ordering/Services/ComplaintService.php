<?php

namespace App\Modules\Ordering\Services;

use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\User;
use App\Modules\Ordering\Exceptions\OrderingException;
use App\Modules\Ordering\Notifications\ComplaintRespondedNotification;
use App\Modules\Payments\Jobs\SendWhatsAppNotificationJob;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\DB;

final class ComplaintService
{
    public const ALLOWED_TARGET_STATUSES = ['processing', 'resolved', 'rejected'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * Admin Koperasi menanggapi dan memperbarui status pengaduan pelanggan (UC-17).
     *
     * @throws OrderingException
     */
    public function respond(
        Koperasi $koperasi,
        Complaint $complaint,
        User $admin,
        string $status,
        string $adminResponse,
    ): Complaint {
        if (! $admin->isAdminKoperasi() || (int) $admin->koperasi_id !== (int) $koperasi->id) {
            throw OrderingException::unauthorizedAction('Hanya Admin Koperasi yang dapat menanggapi pengaduan.');
        }

        if (! in_array($status, self::ALLOWED_TARGET_STATUSES, true)) {
            throw OrderingException::invalidComplaintStatus($status);
        }

        $cleanResponse = trim($adminResponse);
        if ($cleanResponse === '') {
            throw OrderingException::adminResponseRequired();
        }

        return DB::transaction(function () use ($koperasi, $complaint, $admin, $status, $cleanResponse): Complaint {
            $locked = Complaint::query()
                ->withoutGlobalScope('koperasi')
                ->with('customer')
                ->where('koperasi_id', $koperasi->id)
                ->lockForUpdate()
                ->findOrFail($complaint->id);

            if (in_array($locked->status, ['resolved', 'rejected'], true)) {
                throw OrderingException::complaintAlreadyClosed();
            }

            $before = [
                'status' => $locked->status,
                'admin_response' => $locked->admin_response,
            ];

            $locked->forceFill([
                'status' => $status,
                'admin_response' => $cleanResponse,
            ])->save();

            $this->audit->record(
                action: 'complaint_responded',
                modelType: Complaint::class,
                modelId: $locked->id,
                oldValues: $before,
                newValues: ['status' => $status, 'admin_response' => $cleanResponse],
                koperasiId: (int) $koperasi->id,
                userId: (int) $admin->id,
            );

            $customer = $locked->customer;
            if ($customer instanceof Customer) {
                $customer->notify(new ComplaintRespondedNotification(
                    koperasiId: (int) $koperasi->id,
                    complaintId: (int) $locked->id,
                    subject: (string) $locked->subject,
                    status: $status,
                    adminResponse: $cleanResponse,
                ));

                if (is_string($customer->phone) && $customer->phone !== '') {
                    $waMessage = "Tanggapan Pengaduan {$koperasi->name} (\"{$locked->subject}\") — Status: {$status}. {$cleanResponse}";
                    SendWhatsAppNotificationJob::dispatch($customer->phone, $waMessage, 'complaint_response');
                }
            }

            return $locked;
        });
    }
}
