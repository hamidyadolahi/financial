<?php

namespace App\Services\Financial;

use App\Models\Tenant\FinancialStatement;
use App\Models\Tenant\Table6020;
use App\Models\Tenant\Table6020Selected;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

class Table6020Service
{
    /**
     * نگاشت مدل‌ها به route prefix
     */
    protected $modelRoutePrefixes = [
        \App\Models\Tenant\CashInventory::class => 'cash-inventory',
        \App\Models\Tenant\OperationalRevenue::class => 'operational-revenue',
    ];

    /**
     * آماده‌سازی داده‌های انتخاب جدول 6020
     */
    public function prepareTable6020SelectionData(
        int $financialStatementId,
        ?int $noteId,
        ?int $subNoteId,
        string $from,
        ?string $searchKeyword,
        string $modelClass
    ): array {
        $financialStatementId = activeSalemali();

        // آیتم‌های انتخاب‌شده کلی
        $allSelectedItems = Table6020Selected::where('financial_statement_id', $financialStatementId)
            ->get()
            ->keyBy(function ($item) {
                return "{$item->general_code}-{$item->specific_code}-{$item->detail_code}";
            });

        // آیتم‌های انتخاب‌شده برای sub_note_id فعلی
        $currentSubNoteItems = $modelClass::where('financial_statement_id', $financialStatementId)
            ->when($noteId, fn($query) => $query->where('note_id', $noteId))
            ->when($subNoteId, fn($query) => $query->where('sub_note_id', $subNoteId))
            ->whereNotNull('table6020_general_code')
            ->whereNotNull('table6020_specific_code')
            ->whereNotNull('table6020_detail_code')
            ->get()
            ->keyBy(function ($item) {
                return "{$item->table6020_general_code}-{$item->table6020_specific_code}-{$item->table6020_detail_code}";
            });

        // آیتم‌های جدول 6020 برای نمایش
        $items = Table6020::where('financial_year_id', $financialStatementId)
            ->when($searchKeyword, function ($query, $search) {
                $query->where('detail_title', 'like', "%{$search}%")
                      ->orWhere('general_code', 'like', "%{$search}%")
                      ->orWhere('specific_code', 'like', "%{$search}%")
                      ->orWhere('detail_code', 'like', "%{$search}%");
            })
            ->get();


        return [
            'items' => $items,
            'allSelectedItems' => $allSelectedItems,
            'currentSubNoteItems' => $currentSubNoteItems,
            'from' => $from,
            'note_id' => $noteId,
            'sub_note_id' => $subNoteId,
            'model_class' => $modelClass,
            'search' => $searchKeyword,
        ];
    }

    /**
     * آماده‌سازی URL انتخاب جدول 6020 برای ویو
     */
    public function prepareTable6020SelectUrl(
        int $financialStatementId,
        int $noteId,
        int $subNoteId,
        Collection $records,
        string $modelClass
    ): string {
        $routePrefix = $this->getRoutePrefixForModel($modelClass);
    
        $urlParams = [
            'financial_year' => $financialStatementId,
            'from' => Route::is("{$routePrefix}.edit") ? "{$routePrefix}-edit" : $routePrefix,
            'note_id' => $noteId,
            'sub_note_id' => $subNoteId,
            'model_class' => $modelClass,
        ];
    
        if (Route::is("{$routePrefix}.edit")) {
            $selectedIds = $records->pluck('table6020_id')->filter()->toArray();
            $generalCodes = $records->pluck('table6020_general_code')->filter()->toArray();
            $specificCodes = $records->pluck('table6020_specific_code')->filter()->toArray();
            $detailCodes = $records->pluck('table6020_detail_code')->filter()->toArray();
    
            if (!empty($selectedIds)) {
                $urlParams['selected_ids'] = implode(',', $selectedIds);
            }
            if (!empty($generalCodes)) {
                $urlParams['general_codes'] = implode(',', array_filter($generalCodes));
            }
            if (!empty($specificCodes)) {
                $urlParams['specific_codes'] = implode(',', array_filter($specificCodes));
            }
            if (!empty($detailCodes)) {
                $urlParams['detail_codes'] = implode(',', array_filter($detailCodes));
            }
        }
    
        return route('table6020.select', $urlParams);
    }

    /**
     * استخراج route prefix برای مدل داده‌شده
     */
    public function getRoutePrefixForModel(string $modelClass): string
    {
        if (!isset($this->modelRoutePrefixes[$modelClass])) {
            throw new InvalidArgumentException("روت تعریف نشده است: {$modelClass}");
        }

        return $this->modelRoutePrefixes[$modelClass];
    }

    /**
     * گرفتن پارامتر ویرایش برای مدل
     */
    public function getEditParamForModel(string $modelClass): ?string
    {
        $financialNoteService = app(FinancialNoteService::class);
        $config = $financialNoteService->getChildModelConfig(null, $modelClass);
        return $config['edit_param'] ?? null;
    }
}
