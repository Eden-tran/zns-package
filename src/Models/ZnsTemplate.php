<?php

namespace CPP\ZNSPackage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use CPP\ZNSPackage\Validators\ZnsTemplateValidator;

class ZnsTemplate extends Model
{
    use HasFactory;

    const STATUS_ACTIVE = 1;
    const STATUS_PENDING = 2;
    const STATUS_REJECTED = 3;
    const STATUS_INACTIVE = 4;
    const STATUS_DELETED = 5;

    protected $table = 'zns_templates';

    protected $fillable = [
        'oa_id',
        'template_id',
        'template_name',
        'template_content',
        'status',
        'status_raw',
        'preview_url',
        'template_tag',
        'template_quality',
        'template_params',
        'timeout',
        'apply_template_quota',
        'template_daily_quota',
        'template_remaining_quota',
        'price',
        'zalo_created_time',
        'zalo_updated_time',
        'last_synced_at',
        'is_active',
    ];

    protected $casts = [
        'template_params' => 'array',
        'apply_template_quota' => 'boolean',
        'is_active' => 'boolean',
        'zalo_created_time' => 'datetime',
        'zalo_updated_time' => 'datetime',
        'last_synced_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    public $timestamps = true;

    /**
     * Scope to get only active templates
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)
              ->where('is_active', true);
    }

    /**
     * Scope to filter by OA ID
     */
    public function scopeForOA(Builder $query, string $oaId): void
    {
        $query->where('oa_id', $oaId);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus(Builder $query, int $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_PENDING => 'Pending Review',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_DELETED => 'Deleted',
            default => 'Unknown',
        };
    }

    /**
     * Get Vietnamese status label
     */
    public function getStatusViAttribute(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'Đang hoạt động',
            self::STATUS_PENDING => 'Chờ duyệt',
            self::STATUS_REJECTED => 'Bị từ chối',
            self::STATUS_INACTIVE => 'Tạm ngưng',
            self::STATUS_DELETED => 'Đã xóa',
            default => 'Không xác định',
        };
    }

    /**
     * Check if template is usable
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->is_active;
    }

    /**
     * Check if template has available quota
     */
    public function hasQuota(): bool
    {
        if (!$this->apply_template_quota) {
            return true;
        }

        return $this->template_remaining_quota > 0;
    }

    /**
     * Get parameter names for this template
     */
    public function getParameterNames(): array
    {
        if (empty($this->template_params)) {
            return [];
        }

        return array_column($this->template_params, 'name');
    }

    /**
     * Get required parameters
     */
    public function getRequiredParameters(): array
    {
        if (empty($this->template_params)) {
            return [];
        }

        return array_filter($this->template_params, function ($param) {
            return $param['required'] ?? false;
        });
    }

    /**
     * Validate template data against parameters
     *
     * @param array $data Data to validate
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateTemplateData(array $data): array
    {
        if (empty($this->template_params)) {
            return ['valid' => true, 'errors' => []];
        }

        return ZnsTemplateValidator::validate($this->template_params, $data);
    }

    /**
     * Validate template data and throw exception if invalid
     *
     * @param array $data Data to validate
     * @throws \CPP\ZNSPackage\Exceptions\ZNSException
     */
    public function validateTemplateDataOrFail(array $data): void
    {
        if (empty($this->template_params)) {
            return;
        }

        ZnsTemplateValidator::validateOrFail($this->template_params, $data);
    }

    /**
     * Format data according to template requirements
     *
     * @param array $data Data to format
     * @return array Formatted data
     */
    public function formatTemplateData(array $data): array
    {
        if (empty($this->template_params)) {
            return $data;
        }

        return ZnsTemplateValidator::formatData($this->template_params, $data);
    }
}
