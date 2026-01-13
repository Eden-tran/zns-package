<?php

namespace CPP\ZNSPackage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ZnsSendingLog extends Model
{
    use HasFactory;

    const STATUS_PENDING = 0;
    const STATUS_SENT = 1;
    const STATUS_DELIVERED = 2;
    const STATUS_FAILED = 3;

    protected $table = 'zns_sending_logs';

    protected $fillable = [
        'oa_id',
        'phone',
        'template_id',
        'template_data',
        'tracking_id',
        'msg_id',
        'mode',
        'status',
        'error_message',
        'error_code',
        'zns_request',
        'zns_response',
        'sent_at',
        'delivered_at',
        'related_id',
        'related_type',
    ];

    protected $casts = [
        'template_data' => 'array',
        'zns_request' => 'array',
        'zns_response' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public $timestamps = true;

    /**
     * Scope to filter by status
     */
    public function scopeByStatus(Builder $query, int $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to filter by phone
     */
    public function scopeByPhone(Builder $query, string $phone): void
    {
        $query->where('phone', $phone);
    }

    /**
     * Scope to filter by template
     */
    public function scopeByTemplate(Builder $query, string $templateId): void
    {
        $query->where('template_id', $templateId);
    }

    /**
     * Scope to filter failed messages
     */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to filter successful messages
     */
    public function scopeSuccessful(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_SENT => 'Sent',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_FAILED => 'Failed',
            default => 'Unknown',
        };
    }

    /**
     * Get Vietnamese status label
     */
    public function getStatusViAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Đang chờ',
            self::STATUS_SENT => 'Đã gửi',
            self::STATUS_DELIVERED => 'Đã nhận',
            self::STATUS_FAILED => 'Thất bại',
            default => 'Không xác định',
        };
    }

    /**
     * Check if message was sent successfully
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    /**
     * Check if message failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Get the related entity (polymorphic)
     */
    public function relatable()
    {
        return $this->morphTo('related');
    }

    /**
     * Get template
     */
    public function template()
    {
        return $this->belongsTo(ZnsTemplate::class, 'template_id', 'template_id');
    }

    /**
     * Create log entry
     */
    public static function createLog(array $data): self
    {
        return self::create([
            'oa_id' => $data['oa_id'],
            'phone' => $data['phone'],
            'template_id' => $data['template_id'],
            'template_data' => $data['template_data'] ?? [],
            'tracking_id' => $data['tracking_id'] ?? null,
            'mode' => $data['mode'] ?? 'production',
            'status' => self::STATUS_PENDING,
            'zns_request' => $data['request'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'related_type' => $data['related_type'] ?? null,
        ]);
    }

    /**
     * Update log with response
     */
    public function updateWithResponse(array $response): void
    {
        $updateData = [
            'zns_response' => $response,
        ];

        // Check if successful
        if (isset($response['status']) && $response['status'] === 'success') {
            $updateData['status'] = self::STATUS_SENT;
            $updateData['msg_id'] = $response['data']['msg_id'] ?? null;
            $updateData['sent_at'] = now();
        } else {
            // Failed
            $updateData['status'] = self::STATUS_FAILED;
            $updateData['error_code'] = $response['error'] ?? null;
            $updateData['error_message'] = $response['message'] ?? 'Unknown error';
        }

        $this->update($updateData);
    }

    /**
     * Mark as delivered
     */
    public function markAsDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $errorMessage, ?int $errorCode = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
        ]);
    }
}
