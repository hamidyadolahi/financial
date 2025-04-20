<?php

namespace App\Services\Financial;

use App\Models\Tenant\FinancialStatement;
use App\Models\Tenant\Note;
use App\Models\Tenant\Table6020;
use App\Models\Tenant\Table6020Selected;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Table6020SelectionService
{
    protected $financialNoteService;

    public function __construct(FinancialNoteService $financialNoteService)
    {
        $this->financialNoteService = $financialNoteService;
    }

    public function storeSelection(
        array $data,
        string $childModelClass,
        string $parentForeignKey
    ): array {
        $financialStatementId = activeSalemali();
        if (!$financialStatementId) {
            throw new \Exception('سال مالی فعال یافت نشد.');
        }
    
        $config = $this->financialNoteService->getChildModelConfig(null, $childModelClass);
        if (!$config) {
            throw new \Exception('پیکربندی مدل یافت نشد.');
        }
    
        $note = Note::findOrFail($data['note_id'][0]);
    
        return DB::transaction(function () use (
            $note,
            $data,
            $financialStatementId,
            $childModelClass,
            $parentForeignKey,
            $config
        ) {
            $dataToInsert = [];
            $totalCurrentYearAmount = 0;
    
            $selectedItems = $data['selected_items'] ?? [];
            $detailTitles = $data['detail_titles'] ?? [];
            $finalBalances = $data['final_balances'] ?? [];
            $generalCodes = $data['table6020_general_code'] ?? [];
            $specificCodes = $data['table6020_specific_code'] ?? [];
            $detailCodes = $data['table6020_detail_code'] ?? [];
            $subNoteIds = array_slice($data['sub_note_id'] ?? [], 0, count($selectedItems));
            $noteIds = array_slice($data['note_id'] ?? [], 0, count($selectedItems));
            $descriptions = $data['description'] ?? [];
    
            if (empty($selectedItems)) {
                throw new \Exception('حداقل یک آیتم باید انتخاب شود.');
            }
    
            $table6020Items = Table6020::whereIn('id', $selectedItems)->get()->keyBy('id');
            foreach ($selectedItems as $itemId) {
                if (!isset($table6020Items[$itemId])) {
                    throw new \Exception("آیتم جدول 6020 با شناسه $itemId وجود ندارد.");
                }
            }
    
            // ایجاد یا پیدا کردن ProfitLossNote
            $parentNote = $config['parent_model']::firstOrCreate(
                [
                    'financial_statement_id' => $financialStatementId,
                    'note_id' => $note->id,
                ],
                [
                    'title' => 'درآمدهای عملیاتی',
                    'current_year_unit' => 'ریال',
                    'current_year_amount' => 0,
                ]
            );
    
            // حذف رکوردهای قبلی (در صورت وجود)
            $childModelClass::where($parentForeignKey, $parentNote->id)
                ->whereIn('sub_note_id', $subNoteIds)
                ->whereNotNull('table6020_id')
                ->delete();
    
            foreach ($selectedItems as $index => $itemId) {
                if (empty($detailTitles[$itemId])) {
                    throw new \Exception("نام آیتم برای شناسه $itemId الزامی است.");
                }
                if (!isset($finalBalances[$itemId]) || $finalBalances[$itemId] === '') {
                    throw new \Exception("مبلغ سال جاری برای شناسه $itemId الزامی است.");
                }
    
                $subNoteId = $subNoteIds[$index] ?? $subNoteIds[0] ?? null;
                $noteId = $noteIds[$index] ?? $noteIds[0] ?? $note->id;
    
                if ($subNoteId === null) {
                    throw new \Exception("شناسه زیرنوت برای آیتم $itemId الزامی است.");
                }
    
                $currentYearAmount = floatval(str_replace(',', '', $finalBalances[$itemId]));
    
                $rowData = [
                    $parentForeignKey => $parentNote->id,
                    'financial_statement_id' => $financialStatementId,
                    'note_id' => $noteId,
                    'sub_note_id' => $subNoteId,
                    'table6020_id' => $itemId,
                    'item_name' => $detailTitles[$itemId],
                    'current_year_amount' => $currentYearAmount,
                    'table6020_general_code' => $generalCodes[$itemId] ?? null,
                    'table6020_specific_code' => $specificCodes[$itemId] ?? null,
                    'table6020_detail_code' => $detailCodes[$itemId] ?? null,
                    'description' => $descriptions[$itemId] ?? null,
                ];
    
                // اضافه کردن فیلدهای اضافی تعریف‌شده در modelNoteMap
                foreach ($config['extra_fields'] ?? [] as $field) {
                    $rowData[$field] = $data[$field][$itemId] ?? null;
                }
    
                $dataToInsert[] = $rowData;
                $totalCurrentYearAmount += $currentYearAmount;
    
                // ثبت در جدول واسط (فقط با فیلدهای مجاز)
                Table6020Selected::firstOrCreate(
                    [
                        'financial_statement_id' => $financialStatementId,
                        'general_code' => $generalCodes[$itemId] ?? null,
                        'specific_code' => $specificCodes[$itemId] ?? null,
                        'detail_code' => $detailCodes[$itemId] ?? null,
                    ],
                    [
                        'detail_title' => $detailTitles[$itemId],
                    ]
                );
            }
    
            if (!empty($dataToInsert)) {
                $childModelClass::insert($dataToInsert);
            }
    
            $totalCurrentYearAmount += $childModelClass::where($parentForeignKey, $parentNote->id)
                ->whereNull('table6020_id')
                ->sum('current_year_amount');
    
            $parentNote->update([
                'current_year_amount' => $totalCurrentYearAmount,
            ]);
    
            $redirectRoute = $childModelClass::where($parentForeignKey, $parentNote->id)->exists()
                ? route($config['route_prefix'] . '.edit', [$config['edit_param'] => $parentNote->id])
                : route($config['route_prefix'] . '.create');
    
            return [
                'redirect' => $redirectRoute,
                'message' => 'انتخاب با موفقیت ثبت شد.'
            ];
        });
    }

    public function updateSelectedItems(
        Model $parentNote,
        Note $note,
        array $data,
        string $childModelClass,
        string $parentForeignKey,
        string $idField
    ): void {
        $financialStatementId = activeSalemali();
        if (!$financialStatementId) {
            throw new \Exception('سال مالی فعال یافت نشد.');
        }

        $config = $this->financialNoteService->getChildModelConfig(null, $childModelClass);
        if (!$config) {
            throw new \Exception('پیکربندی مدل یافت نشد.');
        }

        DB::transaction(function () use (
            $parentNote,
            $note,
            $data,
            $financialStatementId,
            $childModelClass,
            $parentForeignKey,
            $idField,
            $config
        ) {
            $dataToInsert = [];
            $totalCurrentYearAmount = 0;
            $totalPreviousYearAmount = 0;

            $submittedIds = [];
            if (isset($data[$idField])) {
                foreach ($data[$idField] as $subNoteId => $ids) {
                    $submittedIds = array_merge($submittedIds, array_filter($ids));
                }
            }

            // حذف رکوردهای قدیمی از table_6020_selected و مدل فرزند
            $existingRecords = $childModelClass::where($parentForeignKey, $parentNote->id)->get();
            foreach ($existingRecords as $record) {
                if (!in_array($record->id, $submittedIds)) {
                    // حذف از table_6020_selected
                    Table6020Selected::where('financial_statement_id', $financialStatementId)
                        ->where('general_code', $record->table6020_general_code)
                        ->where('specific_code', $record->table6020_specific_code)
                        ->where('detail_code', $record->table6020_detail_code)
                        ->delete();
                    $record->delete();
                }
            }

            // ثبت آیتم‌های جدید (selected_items)
            if (!empty($data['selected_items'])) {
                $selectedItems = $data['selected_items'] ?? [];
                $detailTitles = $data['detail_titles'] ?? [];
                $finalBalances = $data['final_balances'] ?? [];
                $generalCodes = $data['table6020_general_code'] ?? [];
                $specificCodes = $data['table6020_specific_code'] ?? [];
                $detailCodes = $data['table6020_detail_code'] ?? [];
                $subNoteIds = array_slice($data['sub_note_id'] ?? [], 0, count($selectedItems));
                $noteIds = array_slice($data['note_id'] ?? [], 0, count($selectedItems));
                $descriptions = $data['description'] ?? [];

                $table6020Items = Table6020::whereIn('id', $selectedItems)->get()->keyBy('id');
                foreach ($selectedItems as $itemId) {
                    if (!isset($table6020Items[$itemId])) {
                        throw new \Exception("آیتم جدول 6020 با شناسه $itemId وجود ندارد.");
                    }
                }

                foreach ($selectedItems as $index => $itemId) {
                    if (empty($detailTitles[$itemId])) {
                        throw new \Exception("نام آیتم برای شناسه $itemId الزامی است.");
                    }
                    if (!isset($finalBalances[$itemId]) || $finalBalances[$itemId] === '') {
                        throw new \Exception("مبلغ سال جاری برای شناسه $itemId الزامی است.");
                    }

                    $subNoteId = $subNoteIds[$index] ?? $subNoteIds[0] ?? null;
                    $noteId = $noteIds[$index] ?? $noteIds[0] ?? $note->id;

                    if ($subNoteId === null) {
                        throw new \Exception("شناسه زیرنوت برای آیتم $itemId الزامی است.");
                    }

                    $currentYearAmount = floatval(str_replace(',', '', $finalBalances[$itemId]));
                    $previousYearAmount = isset($data['previous_year_amount'][$itemId]) ? floatval(str_replace(',', '', $data['previous_year_amount'][$itemId])) : 0;

                    $rowData = [
                        $parentForeignKey => $parentNote->id,
                        'financial_statement_id' => $financialStatementId,
                        'note_id' => $noteId,
                        'sub_note_id' => $subNoteId,
                        'table6020_id' => $itemId,
                        'item_name' => $detailTitles[$itemId],
                        'current_year_amount' => $currentYearAmount,
                        'table6020_general_code' => $generalCodes[$itemId] ?? null,
                        'table6020_specific_code' => $specificCodes[$itemId] ?? null,
                        'table6020_detail_code' => $detailCodes[$itemId] ?? null,
                        'description' => $descriptions[$itemId] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // اضافه کردن فیلدهای اضافی تعریف‌شده در modelNoteMap
                    foreach ($config['extra_fields'] ?? [] as $field) {
                        $rowData[$field] = $data[$field][$itemId] ?? null;
                    }

                    $dataToInsert[] = $rowData;
                    $totalCurrentYearAmount += $currentYearAmount;

                    // ثبت در جدول واسط (فقط با فیلدهای مجاز)
                    Table6020Selected::firstOrCreate(
                        [
                            'financial_statement_id' => $financialStatementId,
                            'general_code' => $generalCodes[$itemId] ?? null,
                            'specific_code' => $specificCodes[$itemId] ?? null,
                            'detail_code' => $detailCodes[$itemId] ?? null,
                        ],
                        [
                            'detail_title' => $detailTitles[$itemId],
                        ]
                    );
                }
            }

            // آپدیت رکوردهای موجود
            if (!empty($data[$idField])) {
                $itemNames = $data['item_name'] ?? [];
                $currentYearAmounts = $data['current_year_amount'] ?? [];
                $previousYearAmounts = $data['previous_year_amount'] ?? [];
                $generalCodes = $data['table6020_general_code'] ?? [];
                $specificCodes = $data['table6020_specific_code'] ?? [];
                $detailCodes = $data['table6020_detail_code'] ?? [];
                $subNoteIds = $data['sub_note_id'] ?? [];
                $noteIds = $data['note_id'] ?? [];
                $descriptions = $data['description'] ?? [];
                $recordIds = $data[$idField] ?? [];

                foreach ($recordIds as $subNoteId => $ids) {
                    foreach ($ids as $index => $recordId) {
                        if (!$recordId) {
                            continue;
                        }

                        $itemName = $itemNames[$subNoteId][$index] ?? null;
                        $currentYearAmount = floatval(str_replace(',', '', $currentYearAmounts[$subNoteId][$index] ?? 0));
                        $previousYearAmount = isset($previousYearAmounts[$subNoteId][$index]) ? floatval(str_replace(',', '', $previousYearAmounts[$subNoteId][$index] ?? 0)) : 0;

                        if (empty($itemName)) {
                            throw new \Exception("نام آیتم برای رکورد $recordId الزامی است.");
                        }
                        if ($currentYearAmount === 0) {
                            throw new \Exception("مبلغ سال جاری برای رکورد $recordId الزامی است.");
                        }

                        $record = $childModelClass::find($recordId);
                        if ($record) {
                            $updateData = [
                                'financial_statement_id' => $financialStatementId,
                                'note_id' => $noteIds[$subNoteId][$index] ?? $note->id,
                                'sub_note_id' => $subNoteIds[$subNoteId][$index] ?? $subNoteId,
                                $parentForeignKey => $parentNote->id,
                                'item_name' => $itemName,
                                'current_year_amount' => $currentYearAmount,
                                'table6020_general_code' => $generalCodes[$subNoteId][$index] ?? null,
                                'table6020_specific_code' => $specificCodes[$subNoteId][$index] ?? null,
                                'table6020_detail_code' => $detailCodes[$subNoteId][$index] ?? null,
                                'description' => $descriptions[$subNoteId][$index] ?? null,
                            ];

                            // اضافه کردن فیلدهای اضافی تعریف‌شده در modelNoteMap
                            foreach ($config['extra_fields'] ?? [] as $field) {
                                $updateData[$field] = $data[$field][$subNoteId][$index] ?? null;
                            }

                            $record->update($updateData);
                            $totalCurrentYearAmount += $currentYearAmount;
                        }

                        $previousFinancialStatement = FinancialStatement::where('id', $financialStatementId - 1)->first();
                        if ($previousYearAmount && $previousFinancialStatement) {
                            $previousRowData = [
                                $parentForeignKey => null,
                                'financial_statement_id' => $previousFinancialStatement->id,
                                'note_id' => $noteIds[$subNoteId][$index] ?? $note->id,
                                'sub_note_id' => $subNoteIds[$subNoteId][$index] ?? $subNoteId,
                                'table6020_id' => $record->table6020_id,
                                'item_name' => $itemName,
                                'current_year_amount' => $previousYearAmount,
                                'table6020_general_code' => $generalCodes[$subNoteId][$index] ?? null,
                                'table6020_specific_code' => $specificCodes[$subNoteId][$index] ?? null,
                                'table6020_detail_code' => $detailCodes[$subNoteId][$index] ?? null,
                                'description' => $descriptions[$subNoteId][$index] ?? null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            // اضافه کردن فیلدهای اضافی تعریف‌شده در modelNoteMap برای سال قبل
                            foreach ($config['extra_fields'] ?? [] as $field) {
                                $previousField = 'previous_' . $field;
                                $previousRowData[$field] = $data[$previousField][$subNoteId][$index] ?? null;
                            }

                            $childModelClass::updateOrCreate(
                                [
                                    'financial_statement_id' => $previousFinancialStatement->id,
                                    'note_id' => $noteIds[$subNoteId][$index] ?? $note->id,
                                    'sub_note_id' => $subNoteIds[$subNoteId][$index] ?? $subNoteId,
                                    'item_name' => $itemName,
                                ],
                                $previousRowData
                            );
                            $totalPreviousYearAmount += $previousYearAmount;
                        }
                    }
                }
            }

            if (empty($data['selected_items']) && empty($data[$idField])) {
                throw new \Exception('حداقل یک آیتم باید انتخاب شود یا یک رکورد موجود آپدیت شود.');
            }

            $remainingRecords = $childModelClass::where($parentForeignKey, $parentNote->id)->exists();
            if (!$remainingRecords) {
                $parentNote->delete();
            } else {
                $parentNote->update([
                    'current_year_amount' => $totalCurrentYearAmount,
                    'previous_year_amount' => $totalPreviousYearAmount,
                    'is_restated' => isset($data['is_restated']) ? 1 : 0,
                ]);
            }

            if (!empty($dataToInsert)) {
                $childModelClass::insert($dataToInsert);
            }
        });
    }
}
