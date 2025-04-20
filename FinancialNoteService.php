<?php

namespace App\Services\Financial;

use App\Services\Financial\CashInventoryService;
use App\Models\Tenant\FinancialStatement;
use App\Models\Tenant\AssetNote;
use App\Models\Tenant\CashInventory;
use App\Models\Tenant\Note;
use App\Models\Tenant\Table6020;
use App\Models\Tenant\ProfitLossNote;
use App\Models\Tenant\OperationalRevenue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class FinancialNoteService
{
    protected $table6020Service;

    /**
     * نقشه مدل‌ها به اطلاعات نوت
     *
     * @var array
     */
    public $modelNoteMap = [
        CashInventory::class => [
            'parent_model' => AssetNote::class,
            'note_type' => 'صورت وضعیت مالی',
            'parent_foreign_key' => 'asset_note_id',
            'route_prefix' => 'cash-inventory',
            'edit_param' => 'assetNote',
            'titles' => ['موجودی نقد'],
            'requiresTable6020' => true,
            'table6020_sub_notes' => [],
            'extra_fields' => [], 
        ],
        OperationalRevenue::class => [
            'parent_model' => ProfitLossNote::class,
            'note_type' => 'صورت سود و زیان',
            'parent_foreign_key' => 'profit_loss_note_id',
            'route_prefix' => 'operational-revenue',
            'edit_param' => 'profit_loss_note_id',
            'titles' => ['درآمدهای عملیاتی'],
            'requiresTable6020' => true,
            'table6020_sub_notes' => ['فروش ناخالص', 'درآمد ارایه خدمات'],
            'extra_fields' => ['current_year_quantity', 'current_year_unit'], // فیلدهای اضافی برای OperationalRevenue
        ],
    ];
    

    public function __construct(Table6020Service $table6020Service)
    {
        $this->table6020Service = $table6020Service;
    }

    public function getModelExtraFields(string $modelClass): array
    {
        return $this->modelNoteMap[$modelClass]['extra_fields'] ?? [];
    }

    /**
     * گرفتن مدل فرزند و کلید خارجی بر اساس مدل والد یا فرزند
     */
    public function getChildModelConfig(?string $parentModelClass = null, ?string $childModelClass = null): ?array
    {
        if ($childModelClass && isset($this->modelNoteMap[$childModelClass])) {
            return [
                'child_model' => $childModelClass,
                'parent_model' => $this->modelNoteMap[$childModelClass]['parent_model'],
                'parent_foreign_key' => $this->modelNoteMap[$childModelClass]['parent_foreign_key'],
                'note_type' => $this->modelNoteMap[$childModelClass]['note_type'],
                'route_prefix' => $this->modelNoteMap[$childModelClass]['route_prefix'],
                'edit_param' => $this->modelNoteMap[$childModelClass]['edit_param'],
                'requiresTable6020' => $this->modelNoteMap[$childModelClass]['requiresTable6020'],
                'table6020_sub_notes' => $this->modelNoteMap[$childModelClass]['table6020_sub_notes'] ?? [],
            ];
        }

        if ($parentModelClass) {
            foreach ($this->modelNoteMap as $childModel => $config) {
                if ($config['parent_model'] === $parentModelClass) {
                    return [
                        'child_model' => $childModel,
                        'parent_model' => $config['parent_model'],
                        'parent_foreign_key' => $config['parent_foreign_key'],
                        'note_type' => $config['note_type'],
                        'route_prefix' => $config['route_prefix'],
                        'edit_param' => $config['edit_param'],
                        'requiresTable6020' => $config['requiresTable6020'],
                        'table6020_sub_notes' => $config['table6020_sub_notes'] ?? [],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * گرفتن مسیرهای ایجاد و ویرایش برای مدل
     */
    public function getActionRoutes(string $modelClass, ?string $parentModelClass = null, ?string $noteTitle = null): ?array
    {
        $config = $this->getChildModelConfig($parentModelClass, $modelClass);
        if (!$config) {
            return null;
        }

        $routePrefix = $config['route_prefix'];
        $createRoute = "{$routePrefix}.create";
        $editRoute = "{$routePrefix}.edit";

        if (!Route::has($createRoute) || !Route::has($editRoute)) {
            return null;
        }

        return [
            'create' => $createRoute,
            'edit' => $editRoute,
            'edit_param' => $config['edit_param'],
        ];
    }

    /**
     * آماده‌سازی داده‌های همه‌ی نوت‌ها برای نمایش در جدول شاخص
     */
    public function prepareAllNoteBalances(
        string $modelClass,
        string $noteType,
        string $title,
        ?string $parentModelClass = null,
        ?string $parentForeignKey = null
    ): array {
        $salemaliId = activeSalemali();
        if (!$salemaliId) {
            return [
                'title' => $title,
                'currentYearDate' => null,
                'previousYearDate' => null,
                'currentYearUnit' => null,
                'previousYearUnit' => null,
                'previousOpeningYearDate' => null,
                'preparedNotes' => [],
            ];
        }
    
        $salemali = FinancialStatement::with('financialYear.previousYearComparative', 'financialYear.previousYearComparative2')
            ->find($salemaliId);
        if (!$salemali) {
            return [
                'title' => $title,
                'currentYearDate' => null,
                'previousYearDate' => null,
                'currentYearUnit' => null,
                'previousYearUnit' => null,
                'previousOpeningYearDate' => null,
                'preparedNotes' => [],
            ];
        }
    
        $currentYearDate = $salemali->financialYear?->current_year_date
            ? jdate($salemali->financialYear->current_year_date)->format('Y/m/d')
            : null;
        $currentYear = $salemali->financialYear?->current_year_date
            ? jdate($salemali->financialYear->current_year_date)->format('Y')
            : null;
    
        $previousYearDate = $salemali->financialYear?->previous_year_comparative_date
            ? jdate($salemali->financialYear->previous_year_comparative_date)->format('Y/m/d')
            : null;
        $previousYear = $salemali->financialYear?->previous_year_comparative_date
            ? jdate($salemali->financialYear->previous_year_comparative_date)->format('Y')
            : null;
    
        $currentYearUnit = $salemali->financialYear?->financial_unit;
        $previousYearUnit = $salemali->financialYear?->previousYearComparative2?->financial_unit;
        $previousOpeningYearDate = $salemali->financialYear?->previous_year_opening_date
            ? jdate($salemali->financialYear->previous_year_opening_date)->format('Y/m/d')
            : null;
    
        $previousFinancialStatementId = $previousYearDate
            ? FinancialStatement::where('financial_year_id', $salemali->financialYear->previousYearComparative?->id)->first()?->id
            : null;
        $previousPreviousFinancialStatementId = $previousOpeningYearDate
            ? FinancialStatement::where('financial_year_id', $salemali->financialYear->previousYearComparative2?->id)->first()?->id
            : null;
    
        $config = $this->getChildModelConfig($parentModelClass, $modelClass);
        if (!$config) {
            return [
                'title' => $title,
                'currentYearDate' => $currentYearDate,
                'previousYearDate' => $previousYearDate,
                'previousOpeningYearDate' => $previousOpeningYearDate,
                'preparedNotes' => [],
            ];
        }
    
        $parentNotes = $parentModelClass
            ? $parentModelClass::where('financial_statement_id', $salemali->id)->get()
            : collect([]);
        $previousParentNotes = $previousFinancialStatementId && $parentModelClass
            ? $parentModelClass::where('financial_statement_id', $previousFinancialStatementId)->get()
            : collect([]);
        $previousPreviousParentNotes = $previousPreviousFinancialStatementId && $parentModelClass
            ? $parentModelClass::where('financial_statement_id', $previousPreviousFinancialStatementId)->get()
            : collect([]);
    
        $noteData = getNoteChildren($noteType);
        $noteNumbers = $this->getMainNoteNumbers($salemali->id, $noteData, $parentNotes);
    
        $preparedNotes = [];
    
        foreach ($noteData['subNotes'] as $subNoteData) {
            $subNote = $subNoteData['subNote'];
            $children = [];
    
            $totalCurrentYear = $this->calculateNoteTotalFromCollection(
                collect($subNoteData['children']),
                $parentNotes,
                'current_year_amount',
                $config['parent_model'],
                $config['parent_foreign_key'],
                $config['child_model']
            );
            $totalPreviousYear = $this->calculateNoteTotalFromCollection(
                collect($subNoteData['children']),
                $previousParentNotes,
                'current_year_amount',
                $config['parent_model'],
                $config['parent_foreign_key'],
                $config['child_model']
            );
            $totalPreviousPreviousYear = $this->calculateNoteTotalFromCollection(
                collect($subNoteData['children']),
                $previousPreviousParentNotes,
                'current_year_amount',
                $config['parent_model'],
                $config['parent_foreign_key'],
                $config['child_model']
            );
    
            foreach ($subNoteData['children'] as $childNoteData) {
                $childNote = $childNoteData['subNote'];
                $currentParentNote = $parentNotes->firstWhere('note_id', $childNote->id);
                $previousParentNote = $previousParentNotes->firstWhere('note_id', $childNote->id);
                $previousPreviousParentNote = $previousPreviousParentNotes->firstWhere('note_id', $childNote->id);
    
                $childCurrentYearAmount = 0;
                $childPreviousYearAmount = 0;
                $hasData = false;
    
                if ($currentParentNote && $config['parent_model'] && $config['parent_foreign_key']) {
                    $childCurrentYearAmount = $config['child_model']::where($config['parent_foreign_key'], $currentParentNote->id)
                        ->sum('current_year_amount');
                    $hasData = $config['child_model']::where($config['parent_foreign_key'], $currentParentNote->id)->exists();
                } else {
                    // اگه parentNote وجود نداشت، هیچ رکوردی نساز
                    $childCurrentYearAmount = 0;
                    $hasData = false;
                }
    
                if ($previousParentNote && $config['parent_model'] && $config['parent_foreign_key']) {
                    $childPreviousYearAmount = $config['child_model']::where($config['parent_foreign_key'], $previousParentNote->id)
                        ->sum('current_year_amount');
                }
    
                $hasModel = false;
                $specificNoteActionRoutes = [
                    'create' => null,
                    'edit' => null,
                    'edit_param' => null,
                ];
                $requiresTable6020 = false;
    
                foreach ($this->modelNoteMap as $model => $modelConfig) {
                    if ($modelConfig['note_type'] === $noteType && in_array(trim($childNote->title), array_map('trim', $modelConfig['titles'] ?? []))) {
                        $hasModel = true;
                        $specificNoteActionRoutes = $this->getActionRoutes($model) ?? [
                            'create' => null,
                            'edit' => null,
                            'edit_param' => null,
                        ];
                        $requiresTable6020 = in_array(trim($childNote->title), $modelConfig['table6020_sub_notes'] ?? []);
                        break;
                    }
                }
    
                $grandChildren = [];
                if (isset($childNoteData['children']) && $childNoteData['children'] instanceof Collection && $childNoteData['children']->isNotEmpty()) {
                    $balances = $this->calculateNoteBalances(
                        $childNoteData['children'],
                        $config['child_model'],
                        $salemali->id,
                        $previousFinancialStatementId,
                        $previousPreviousFinancialStatementId,
                        'current_year_amount',
                        'current_year_amount',
                        $config['parent_model'],
                        $config['parent_foreign_key']
                    );
    
                    foreach ($childNoteData['children'] as $grandChildNoteData) {
                        $grandChildNote = $grandChildNoteData['subNote'];
                        $parentNote = $config['parent_model']
                            ? $parentNotes->firstWhere('note_id', $grandChildNote->id)
                            : null;
    
                        $grandChildHasModel = false;
                        $grandChildNoteActionRoutes = [
                            'create' => null,
                            'edit' => null,
                            'edit_param' => null,
                        ];
                        $grandChildRequiresTable6020 = false;
    
                        foreach ($this->modelNoteMap as $model => $modelConfig) {
                            if ($modelConfig['note_type'] === $noteType && in_array(trim($grandChildNote->title), array_map('trim', $modelConfig['titles'] ?? []))) {
                                $grandChildHasModel = true;
                                $grandChildNoteActionRoutes = $this->getActionRoutes($model) ?? [
                                    'create' => null,
                                    'edit' => null,
                                    'edit_param' => null,
                                ];
                                $grandChildRequiresTable6020 = in_array(trim($grandChildNote->title), $modelConfig['table6020_sub_notes'] ?? []);
                                break;
                            }
                        }
    
                        $grandChildren[] = [
                            'title' => $grandChildNote->title,
                            'noteNumber' => $noteNumbers[$grandChildNote->id] ?? '-',
                            'currentYearAmount' => $balances['currentYearBalances'][$grandChildNote->id] ?? 0,
                            'previousYearAmount' => $balances['previousYearBalances'][$grandChildNote->id] ?? 0,
                            'previousPreviousYearAmount' => $balances['previousPreviousYearBalances'][$grandChildNote->id] ?? 0,
                            'parentNoteId' => $parentNote ? $parentNote->id : null,
                            'createRoute' => $grandChildHasModel ? $grandChildNoteActionRoutes['create'] : null,
                            'editRoute' => $grandChildHasModel ? $grandChildNoteActionRoutes['edit'] : null,
                            'editRouteParam' => $grandChildHasModel ? $grandChildNoteActionRoutes['edit_param'] : null,
                            'hasModel' => $grandChildHasModel,
                            'requiresTable6020' => $grandChildRequiresTable6020,
                        ];
                    }
    
                    $grandChildren[] = [
                        'title' => "Total {$childNote->title}",
                        'noteNumber' => '',
                        'currentYearAmount' => $balances['currentYearSum'],
                        'previousYearAmount' => $balances['previousYearSum'],
                        'previousPreviousYearAmount' => $balances['previousPreviousYearSum'],
                        'parentNoteId' => null,
                        'createRoute' => null,
                        'editRoute' => null,
                        'editRouteParam' => null,
                        'hasModel' => false,
                        'requiresTable6020' => false,
                    ];
                }
    
                $children[] = [
                    'title' => $childNote->title,
                    'noteNumber' => $noteNumbers[$childNote->id] ?? '-',
                    'currentYearAmount' => $childCurrentYearAmount,
                    'previousYearAmount' => $childPreviousYearAmount,
                    'previousPreviousYearAmount' => $previousPreviousParentNote ? ($previousPreviousParentNote->current_year_amount ?? 0) : 0,
                    'parentNoteId' => $currentParentNote ? $currentParentNote->id : null,
                    'createRoute' => $hasModel ? $specificNoteActionRoutes['create'] : null,
                    'editRoute' => $hasModel ? $specificNoteActionRoutes['edit'] : null,
                    'editRouteParam' => $hasModel ? $specificNoteActionRoutes['edit_param'] : null,
                    'hasModel' => $hasModel,
                    'grandChildren' => $grandChildren,
                    'hasData' => $hasData,
                    'requiresTable6020' => $requiresTable6020,
                ];
            }
    
            $preparedNotes[] = [
                'title' => $subNote->title,
                'children' => $children,
                'totalCurrentYear' => $totalCurrentYear,
                'totalPreviousYear' => $totalPreviousYear,
                'totalPreviousPreviousYear' => $totalPreviousPreviousYear,
            ];
        }
    
        return [
            'title' => $title,
            'currentYearDate' => $currentYearDate,
            'previousYearDate' => $previousYearDate,
            'currentYear' => $currentYear,
            'previousYear' => $previousYear,
            'currentYearUnit' => $currentYearUnit,
            'previousYearUnit' => $previousYearUnit,
            'previousOpeningYearDate' => $previousOpeningYearDate,
            'preparedNotes' => $preparedNotes,
        ];
    }

    /**
     * محاسبه‌ی مقادیر و جمع کل‌ها برای نوت‌ها با کوئری مستقیم از مدل
     */
    public function calculateNoteBalances(
        Collection $notes,
        string $modelClass,
        ?int $currentFinancialStatementId,
        ?int $previousFinancialStatementId,
        ?int $previousPreviousFinancialStatementId = null,
        string $currentYearField = 'current_year_amount',
        string $previousYearField = 'current_year_amount',
        ?string $parentModelClass = null,
        ?string $parentForeignKey = null
    ): array {
        $config = $this->getChildModelConfig($parentModelClass, $modelClass);
        if (!$config) {
            return [
                'currentYearBalances' => [],
                'previousYearBalances' => [],
                'previousPreviousYearBalances' => [],
                'currentYearSum' => 0,
                'previousYearSum' => 0,
                'previousPreviousYearSum' => 0,
            ];
        }

        $currentYearBalances = [];
        $previousYearBalances = [];
        $previousPreviousYearBalances = [];
        $selectedItems = [];

        foreach ($notes as $noteData) {
            $note = $noteData['subNote'] ?? $noteData;
            $selectedItems[] = $note->id;

            $parentNote = $config['parent_model']
                ? $config['parent_model']::where('financial_statement_id', $currentFinancialStatementId)
                    ->where('note_id', $note->id)
                    ->first()
                : null;

            $currentYearAmount = 0;
            if ($currentFinancialStatementId) {
                if ($config['parent_model'] && $parentNote) {
                    $query = $config['child_model']::where($config['parent_foreign_key'], $parentNote->id);
                } else {
                    $query = $config['child_model']::where('note_id', $note->id)
                        ->where('financial_statement_id', $currentFinancialStatementId);
                }
                $currentYearAmount = $query->sum($currentYearField);
            }
            $currentYearBalances[$note->id] = $currentYearAmount;

            $previousYearAmount = 0;
            if ($previousFinancialStatementId) {
                $previousParentNote = $config['parent_model']
                    ? $config['parent_model']::where('financial_statement_id', $previousFinancialStatementId)
                        ->where('note_id', $note->id)
                        ->first()
                    : null;

                if ($config['parent_model'] && $previousParentNote) {
                    $query = $config['child_model']::where($config['parent_foreign_key'], $previousParentNote->id);
                } else {
                    $query = $config['child_model']::where('note_id', $note->id)
                        ->where('financial_statement_id', $previousFinancialStatementId);
                }
                $previousYearAmount = $query->sum($currentYearField);
            }
            $previousYearBalances[$note->id] = $previousYearAmount;

            $previousPreviousYearAmount = 0;
            if ($previousPreviousFinancialStatementId) {
                $previousPreviousParentNote = $config['parent_model']
                    ? $config['parent_model']::where('financial_statement_id', $previousPreviousFinancialStatementId)
                        ->where('note_id', $note->id)
                        ->first()
                    : null;

                if ($config['parent_model'] && $previousPreviousParentNote) {
                    $query = $config['child_model']::where($config['parent_foreign_key'], $previousPreviousParentNote->id);
                } else {
                    $query = $config['child_model']::where('note_id', $note->id)
                        ->where('financial_statement_id', $previousPreviousFinancialStatementId);
                }
                $previousPreviousYearAmount = $query->sum($currentYearField);
            }
            $previousPreviousYearBalances[$note->id] = $previousPreviousYearAmount;
        }

        $currentYearSum = array_sum($currentYearBalances);
        $previousYearSum = array_sum($previousYearBalances);
        $previousPreviousYearSum = array_sum($previousPreviousYearBalances);

        return [
            'currentYearBalances' => $currentYearBalances,
            'previousYearBalances' => $previousYearBalances,
            'previousPreviousYearBalances' => $previousPreviousYearBalances,
            'currentYearSum' => $currentYearSum,
            'previousYearSum' => $previousYearSum,
            'previousPreviousYearSum' => $previousPreviousYearSum,
        ];
    }

    /**
     * محاسبه‌ی مقادیر و جمع کل‌ها از یه کالکشن آماده
     */
    public function calculateNoteTotalFromCollection(
        Collection $notes,
        Collection $dataRecords,
        string $amountField = 'current_year_amount',
        ?string $parentModelClass = null,
        ?string $parentForeignKey = null,
        ?string $childModelClass = null
    ): float {
        $config = $this->getChildModelConfig($parentModelClass, $childModelClass);
        if (!$config) {
            return 0;
        }

        $total = 0;

        foreach ($notes as $noteData) {
            $note = $noteData['subNote'] ?? $noteData;
            $parentNote = $dataRecords->firstWhere('note_id', $note->id);

            if ($parentNote && $config['child_model'] && $config['parent_model']) {
                $childRecords = $config['child_model']::where($config['parent_foreign_key'], $parentNote->id)->get();
                $total += $childRecords->sum($amountField);
            } elseif ($parentNote) {
                $total += (float) ($parentNote->{$amountField} ?? 0);
            }
        }

        return $total;
    }

    /**
     * شماره‌گذاری نمایشی زیرنوت‌ها و توضیحات برای ویو و چاپ
     */
    public function getNoteNumbers(
        array $noteData,
        Collection $dataRecords,
        Collection $childRecords,
        int $noteId,
        int $startNumber
    ): array {
        $mainNoteNumber = $childRecords->isNotEmpty() ? $startNumber : null;
        $subNoteNumbers = [];
        $descriptionNumbers = [];
    
        if ($childRecords->isNotEmpty()) {
            $groupedRecords = $childRecords->groupBy('sub_note_id')->sortKeys();
            $subNoteCounter = 0;
    
            foreach ($groupedRecords as $subNoteId => $records) {
                if ($records->isNotEmpty()) {
                    $subNote = Note::find($subNoteId);
                    if ($subNote && $subNote->has_note_number == 1) {
                        $subNoteCounter++;
                        $subNoteNumbers[$subNoteId] = $mainNoteNumber . '-' . $subNoteCounter;
    
                        $descriptionCounter = 0;
                        foreach ($records as $index => $row) {
                            if (!empty(trim($row->description))) {
                                $descriptionCounter++;
                                $descriptionNumbers[$subNoteId][$index] = $mainNoteNumber . '-' . $subNoteCounter . '-' . $descriptionCounter;
                            }
                        }
                    }
                }
            }
        }
    
        return [
            'mainNoteNumber' => $mainNoteNumber,
            'subNoteNumbers' => $subNoteNumbers,
            'descriptionNumbers' => $descriptionNumbers,
            'subNoteDescriptionNumbers' => [],
        ];
    }

    /**
     * گرفتن لیست نوت‌های موجود و تخصیص شماره‌های ترتیبی بر اساس وجود دیتا در مدل‌های فرزند
     */
    public function getMainNoteNumbers(int $financialStatementId, array $noteData, Collection $parentNotes): array
    {
        $noteMap = [];
        $noteCounter = 5;

        foreach ($this->modelNoteMap as $childModel => $config) {
            $hasData = $childModel::where('financial_statement_id', $financialStatementId)
                ->whereNotNull($config['parent_foreign_key'])
                ->exists();

            if ($hasData) {
                $titles = $config['titles'] ?? [];
                if (!empty($titles)) {
                    $notes = Note::whereIn('title', $titles)
                        ->where('has_note_number', 1)
                        ->get();
                    foreach ($notes as $note) {
                        $noteMap[$note->id] = $noteCounter++;
                    }
                }
            }
        }

        foreach ($noteData['subNotes'] as $subNoteData) {
            foreach ($subNoteData['children'] as $childLevel1) {
                $note = $childLevel1['subNote'];
                if ($note->has_note_number == 1 && isset($noteMap[$note->id])) {
                    $noteMap[$note->id] = $noteMap[$note->id];
                }
                if (isset($childLevel1['children'])) {
                    foreach ($childLevel1['children'] as $childLevel2) {
                        $subNote = $childLevel2['subNote'];
                        if ($subNote->has_note_number == 1 && isset($noteMap[$subNote->id])) {
                            $noteMap[$subNote->id] = $noteMap[$subNote->id];
                        }
                    }
                }
            }
        }

        return $noteMap;
    }

    /**
     * گرفتن شماره اصلی نوت بر اساس مدل و سال مالی
     */
    public function getMainNoteNumber(string $modelClass, int $financialStatementId, Note $note): int
    {
        $config = $this->getChildModelConfig(null, $modelClass);
        if (!$config) {
            return 5;
        }

        $noteData = getNoteChildren($config['note_type']);
        $parentNotes = $config['parent_model']::where('financial_statement_id', $financialStatementId)->get();

        $noteMap = $this->getMainNoteNumbers($financialStatementId, $noteData, $parentNotes);
        return $noteMap[$note->id] ?? 5;
    }

    /**
     * پیدا کردن یادداشت بر اساس عنوان
     */
    public function findNoteByTitle(string $title): ?Note
    {
        return Note::where('title', $title)->first();
    }

    /**
     * جلوگیری از ایجاد داده‌ی تکراری برای سال مالی فعال
     */
    public function preventDuplicateCreation(
        string $modelClass,
        string $redirectRoute,
        string $warningMessage
    ) {
        $financialStatementId = activeSalemali();
        if (!$financialStatementId) {
            alert()->error('سال مالی فعال یافت نشد.', 'خطا')->persistent('تأیید');
            return redirect()->route('dashboard');
        }

        $existingRecord = null;

        if ($modelClass === CashInventory::class) {
            // چک کردن وجود رکورد توی cash_inventories به جای asset_notes
            $existingRecord = CashInventory::where('financial_statement_id', $financialStatementId)
                ->first();

            // اگه رکورد توی cash_inventories بود، پیدا کردن AssetNote مربوطه برای ریدایرکت
            if ($existingRecord) {
                $assetNote = AssetNote::where('id', $existingRecord->asset_note_id)
                    ->where('financial_statement_id', $financialStatementId)
                    ->first();

                if ($assetNote && $redirectRoute === 'cash-inventory.edit') {
                    alert()->warning($warningMessage, 'پیغام سیستم')->persistent("تأیید");
                    return redirect()->route($redirectRoute, ['assetNote' => $assetNote->id]);
                }
            }
        }

        return null;
    }

    /**
     * آماده‌سازی داده‌ها برای ایجاد یا ویرایش
     */
    public function prepareFormData(
        string $modelClass,
        Note $note,
        ?Model $parentNote,
        string $actionRoute,
        string $title,
        ?string $parentModelClass = null,
        ?string $parentForeignKey = null
    ): array {
        $salemaliId = activeSalemali();
        if (!$salemaliId) {
            return ['error' => 'سال مالی فعال یافت نشد'];
        }
    
        $salemali = FinancialStatement::with('financialYear')->find($salemaliId);
        if (!$salemali) {
            return ['error' => 'سال مالی فعال یافت نشد'];
        }
    
        $currentYearDate = $salemali->financialYear?->current_year_date
            ? jdate($salemali->financialYear->current_year_date)->format('Y/m/d')
            : null;
        $currentYear = $salemali->financialYear?->current_year_date
            ? jdate($salemali->financialYear->current_year_date)->format('Y')
            : null;
        $currentYearUnit = $salemali->financialYear?->financial_unit;
    
        $previousYear = $salemali->financialYear?->previousYearComparative2?->current_year_date
            ? jdate($salemali->financialYear->previousYearComparative2->current_year_date)->format('Y')
            : null;
        $previousYearUnit = $salemali->financialYear?->previousYearComparative2?->financial_unit;
    
        $config = $this->getChildModelConfig($parentModelClass, $modelClass);
        if (!$config) {
            return ['error' => 'پیکربندی مدل یافت نشد'];
        }
    
        $previousData = $this->loadPreviousYearData($note, $config['parent_model'], $config['child_model']);
        $noteData = getNoteChildren($config['note_type']);
        $subNotesData = $this->extractSubNotes($noteData, $note->title);
    
        $records = $parentNote
            ? $config['child_model']::where($config['parent_foreign_key'], $parentNote->id)->get()
            : collect();
    
        $preparedTitles = $this->prepareTitlesData(
            $subNotesData['titles'],
            $records,
            $previousData['previousRecords'],
            $config['child_model'],
            $config['parent_foreign_key'],
            $note->id
        );
    
        // ساخت table6020Urls برای زیرنوت‌های وابسته به 6020
        $table6020Urls = [];
        foreach ($preparedTitles as $subNoteId => $subNoteData) {
            $subNote = $subNoteData['title'];
            if (in_array($subNote->title, $config['table6020_sub_notes'] ?? [])) {
                $table6020Urls[$subNoteId] = $this->table6020Service->prepareTable6020SelectUrl(
                    financialStatementId: $salemaliId,
                    noteId: $note->id,
                    subNoteId: $subNoteId,
                    records: $records->where('sub_note_id', $subNoteId),
                    modelClass: $modelClass
                );
            }
        }
    
        $noteNumbers = $this->getMainNoteNumbers($salemaliId, $noteData, collect([$parentNote]));
        $mainNoteNumber = $noteNumbers[$note->id] ?? 5;
        $noteNumbering = $this->getNoteNumbers(
            $noteData,
            $parentNote ? collect([$parentNote]) : collect(),
            $records,
            $note->id,
            $mainNoteNumber
        );
    
        return [
            'salemali' => $salemali,
            'currentYearDate' => $currentYearDate,
            'currentYear' => $currentYear,
            'currentYearUnit' => $currentYearUnit,
            'previousYear' => $previousYear,
            'previousYearUnit' => $previousYearUnit,
            'parentNote' => $parentNote,
            'records' => $records,
            'previousFinancialStatement' => $previousData['previousFinancialStatement'],
            'previousParentNote' => $previousData['previousParentNote'],
            'previousRecords' => $previousData['previousRecords'],
            'action' => $actionRoute,
            'title' => $title,
            'noteData' => $noteData,
            'note' => $note,
            'titles' => $subNotesData['titles'],
            'preparedTitles' => $preparedTitles,
            'noteNumbering' => $noteNumbering,
            'requiresTable6020' => !empty($config['table6020_sub_notes']), // برای ویو، مشخص می‌کنه که فرم 6020 داره
            'table6020Urls' => $table6020Urls,
        ];
    }

    /**
     * لود داده‌های سال قبل
     */
    protected function loadPreviousYearData(Note $note, ?string $parentModelClass = null, ?string $childModelClass = null): array
    {
        $salemaliId = activeSalemali();
        if (!$salemaliId) {
            return [
                'previousFinancialStatement' => null,
                'previousParentNote' => null,
                'previousRecords' => collect(),
                'previousYearDate' => null,
            ];
        }

        $salemali = FinancialStatement::with('financialYear.previousYearComparative')->find($salemaliId);
        if (!$salemali) {
            return [
                'previousFinancialStatement' => null,
                'previousParentNote' => null,
                'previousRecords' => collect(),
                'previousYearDate' => null,
            ];
        }

        $previousFinancialYear = $salemali->financialYear?->previousYearComparative;
        $previousYearDate = $salemali->financialYear?->previous_year_comparative_date
            ? jdate($salemali->financialYear->previous_year_comparative_date)->format('Y/m/d')
            : null;

        $previousFinancialStatement = null;
        $previousParentNote = null;
        $previousRecords = collect();

        $config = $this->getChildModelConfig($parentModelClass, $childModelClass);
        if ($previousFinancialYear && $config) {
            $previousFinancialStatement = FinancialStatement::where('financial_year_id', $previousFinancialYear->id)->first();
            if ($previousFinancialStatement && $config['parent_model']) {
                $previousParentNote = $config['parent_model']::where('financial_statement_id', $previousFinancialStatement->id)
                    ->where('note_id', $note->id)
                    ->first();

                if ($previousParentNote) {
                    $previousRecords = $config['child_model']::where($config['parent_foreign_key'], $previousParentNote->id)->get();
                }
            }
        }

        dd([
            'previousFinancialStatement' => $previousFinancialStatement,
            'previousParentNote' => $previousParentNote,
            'previousRecords' => $previousRecords,
            'previousYearDate' => $previousYearDate,
        ]);
        return [
            'previousFinancialStatement' => $previousFinancialStatement,
            'previousParentNote' => $previousParentNote,
            'previousRecords' => $previousRecords,
            'previousYearDate' => $previousYearDate,
        ];
    }

    /**
     * استخراج زیرنوت‌های مربوط به یک عنوان خاص
     */
    protected function extractSubNotes(array $noteData, string $noteTitle): array
    {
        $subNotes = collect();
        $targetNote = null;

        foreach ($noteData['subNotes'] as $subNoteData) {
            foreach ($subNoteData['children'] as $childLevel1) {
                if ($childLevel1['subNote']->title === $noteTitle) {
                    $targetNote = $childLevel1['subNote'];
                    $subNotes = $childLevel1['children'] ?? collect();
                    break 2;
                }
                if (isset($childLevel1['children'])) {
                    foreach ($childLevel1['children'] as $childLevel2) {
                        if ($childLevel2['subNote']->title === $noteTitle) {
                            $targetNote = $childLevel2['subNote'];
                            $subNotes = $childLevel2['children'] ?? collect();
                            break 3;
                        }
                    }
                }
            }
        }

        return [
            'note' => $targetNote,
            'subNotes' => $subNotes,
            'titles' => $subNotes->map(fn($subNoteData) => $subNoteData['subNote']),
        ];
    }


    /**
     * آماده‌سازی داده‌های عناوین برای نمایش
     */
    protected function prepareTitlesData(
        Collection $titles,
        Collection $records,
        Collection $previousRecords,
        string $modelClass,
        ?string $foreignKey = null,
        ?int $noteId = null
    ): array {
        $config = $this->getChildModelConfig(null, $modelClass);
        if (!$config) {
            return [];
        }

        $preparedTitles = [];

        foreach ($titles as $title) {
            $subNoteRecords = $records->where('sub_note_id', $title->id)->values();
            $previousSubNoteRecords = $previousRecords->where('sub_note_id', $title->id)
                ->keyBy('item_name');

            $currentYearSum = $subNoteRecords->sum('current_year_amount');
            $previousYearSum = $previousSubNoteRecords->sum('current_year_amount');

            $rows = [];
            foreach ($subNoteRecords as $index => $record) {
                $previousRecord = $previousSubNoteRecords->has($record->item_name)
                    ? $previousSubNoteRecords[$record->item_name]
                    : null;
                $itemName = $record->item_name;
                $isEditable = request()->routeIs('*.edit');

                $rows[] = [
                    'index' => $index,
                    'id' => $record->id,
                    'currentInventory' => $record,
                    'previousInventory' => $previousRecord,
                    'itemName' => $itemName,
                    'isEditable' => $isEditable,
                    'previous_year_quantity' => $previousRecord ? $previousRecord->current_year_quantity : null,
                ];
            }

            if (!request()->routeIs('*.edit')) {
                foreach ($previousSubNoteRecords as $itemName => $previousRecord) {
                    if (!$subNoteRecords->contains('item_name', $itemName)) {
                        $rows[] = [
                            'index' => count($rows),
                            'id' => null,
                            'currentInventory' => null,
                            'previousInventory' => $previousRecord,
                            'itemName' => $itemName,
                            'isEditable' => false,
                            'previous_year_quantity' => $previousRecord->current_year_quantity,
                        ];
                    }
                }
            }

            $selectUrl = null;
            // فقط برای زیرنوت‌های مشخص‌شده توی table6020_sub_notes
            if (in_array($title->title, $config['table6020_sub_notes'] ?? [])) {
                $selectUrl = $this->table6020Service->prepareTable6020SelectUrl(
                    financialStatementId: activeSalemali(),
                    noteId: $noteId,
                    subNoteId: $title->id,
                    records: $subNoteRecords,
                    modelClass: $modelClass
                );
            }

            $preparedTitles[$title->id] = [
                'title' => $title,
                'children' => collect(),
                'currentYearSum' => $currentYearSum,
                'previousYearSum' => $previousYearSum,
                'rows' => $rows,
                'selectedTable6020Ids' => $subNoteRecords->pluck('table6020_id')->filter()->toArray(),
                'selectUrl' => $selectUrl,
            ];
        }

        return $preparedTitles;
    }


    /**
     * ذخیره آیتم‌های انتخاب‌شده برای مدل‌های عمومی (غیر از CashInventory)
     */
    public function storeSelectedItems(
        Model $parentNote,
        Note $note,
        array $data
    ): void {
        $financialStatementId = activeSalemali();
        if (!$financialStatementId) {
            throw new \Exception('سال مالی فعال یافت نشد.');
        }

        DB::transaction(function () use (
            $parentNote,
            $note,
            $data,
            $financialStatementId
        ) {
            $dataToInsert = [];
            $totalCurrentYearAmount = 0;
            $totalPreviousYearAmount = 0;

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

            $previousFinancialStatement = FinancialStatement::where('id', $financialStatementId - 1)->first();

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

                // سال جاری
                $rowData = [
                    'asset_note_id' => $parentNote->id,
                    'financial_statement_id' => $financialStatementId,
                    'note_id' => $noteId,
                    'sub_note_id' => $subNoteId,
                    'table6020_id' => $itemId,
                    'item_name' => $detailTitles[$itemId],
                    'current_year_amount' => $currentYearAmount,
                    'table6020_general_code' => $generalCodes[$itemId] ?? null,
                    'table6020_specific_code' => $specificCodes[$itemId] ?? null,
                    'table6020_detail_code' => $detailCodes[$itemId] ?? null,
                    'description' => $descriptions[$subNoteId][$index] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $dataToInsert[] = $rowData;
                $totalCurrentYearAmount += $currentYearAmount;

                // سال قبل
                if ($previousYearAmount && $previousFinancialStatement) {
                    $previousRowData = [
                        'asset_note_id' => null,
                        'financial_statement_id' => $previousFinancialStatement->id,
                        'note_id' => $noteId,
                        'sub_note_id' => $subNoteId,
                        'table6020_id' => $itemId,
                        'item_name' => $detailTitles[$itemId],
                        'current_year_amount' => $previousYearAmount,
                        'table6020_general_code' => $generalCodes[$itemId] ?? null,
                        'table6020_specific_code' => $specificCodes[$itemId] ?? null,
                        'table6020_detail_code' => $detailCodes[$itemId] ?? null,
                        'description' => $descriptions[$subNoteId][$index] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    CashInventory::updateOrCreate(
                        [
                            'financial_statement_id' => $previousFinancialStatement->id,
                            'note_id' => $noteId,
                            'sub_note_id' => $subNoteId,
                            'item_name' => $detailTitles[$itemId],
                        ],
                        $previousRowData
                    );
                    $totalPreviousYearAmount += $previousYearAmount;
                }
            }

            if (!empty($dataToInsert)) {
                CashInventory::insert($dataToInsert);
            }

            $parentNote->update([
                'current_year_amount' => $totalCurrentYearAmount,
                'previous_year_amount' => $totalPreviousYearAmount,
                'is_restated' => isset($data['is_restated']) ? 1 : 0,
            ]);
        });
    }

    /**
     * آپدیت آیتم‌های انتخاب‌شده برای مدل‌های عمومی (غیر از CashInventory)
     */
    public function updateSelectedItems(
        Model $parentNote,
        Note $note,
        array $data
    ): void {
        $financialStatementId = activeSalemali();
        if (!$financialStatementId) {
            throw new \Exception('سال مالی فعال یافت نشد.');
        }

        DB::transaction(function () use (
            $parentNote,
            $note,
            $data,
            $financialStatementId
        ) {
            $dataToInsert = [];
            $totalCurrentYearAmount = 0;
            $totalPreviousYearAmount = 0;

            $idField = 'cash_inventory_id';
            $submittedIds = [];
            if (isset($data[$idField])) {
                foreach ($data[$idField] as $subNoteId => $ids) {
                    $submittedIds = array_merge($submittedIds, array_filter($ids));
                }
            }

            $existingRecords = CashInventory::where('asset_note_id', $parentNote->id)->get();
            foreach ($existingRecords as $record) {
                if (!in_array($record->id, $submittedIds)) {
                    $record->delete();
                }
            }

            $previousFinancialStatement = FinancialStatement::where('id', $financialStatementId - 1)->first();

            // ردیف‌های جدید (selected_items)
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

                    // سال جاری
                    $rowData = [
                        'asset_note_id' => $parentNote->id,
                        'financial_statement_id' => $financialStatementId,
                        'note_id' => $noteId,
                        'sub_note_id' => $subNoteId,
                        'table6020_id' => $itemId,
                        'item_name' => $detailTitles[$itemId],
                        'current_year_amount' => $currentYearAmount,
                        'table6020_general_code' => $generalCodes[$itemId] ?? null,
                        'table6020_specific_code' => $specificCodes[$itemId] ?? null,
                        'table6020_detail_code' => $detailCodes[$itemId] ?? null,
                        'description' => $descriptions[$subNoteId][$index] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $dataToInsert[] = $rowData;
                    $totalCurrentYearAmount += $currentYearAmount;

                    // سال قبل
                    if ($previousYearAmount && $previousFinancialStatement) {
                        $previousRowData = [
                            'asset_note_id' => null,
                            'financial_statement_id' => $previousFinancialStatement->id,
                            'note_id' => $noteId,
                            'sub_note_id' => $subNoteId,
                            'table6020_id' => $itemId,
                            'item_name' => $detailTitles[$itemId],
                            'current_year_amount' => $previousYearAmount,
                            'table6020_general_code' => $generalCodes[$itemId] ?? null,
                            'table6020_specific_code' => $specificCodes[$itemId] ?? null,
                            'table6020_detail_code' => $detailCodes[$itemId] ?? null,
                            'description' => $descriptions[$subNoteId][$index] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        CashInventory::updateOrCreate(
                            [
                                'financial_statement_id' => $previousFinancialStatement->id,
                                'note_id' => $noteId,
                                'sub_note_id' => $subNoteId,
                                'item_name' => $detailTitles[$itemId],
                            ],
                            $previousRowData
                        );
                        $totalPreviousYearAmount += $previousYearAmount;
                    }
                }
            }

            // ردیف‌های موجود (cash_inventory_id)
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

                        $noteId = $noteIds[$subNoteId][$index] ?? $note->id;
                        $subNoteIdVal = $subNoteIds[$subNoteId][$index] ?? $subNoteId;

                        // سال جاری
                        $record = CashInventory::find($recordId);
                        if ($record) {
                            $record->update([
                                'financial_statement_id' => $financialStatementId,
                                'note_id' => $noteId,
                                'sub_note_id' => $subNoteIdVal,
                                'asset_note_id' => $parentNote->id,
                                'item_name' => $itemName,
                                'current_year_amount' => $currentYearAmount,
                                'table6020_general_code' => $generalCodes[$subNoteId][$index] ?? null,
                                'table6020_specific_code' => $specificCodes[$subNoteId][$index] ?? null,
                                'table6020_detail_code' => $detailCodes[$subNoteId][$index] ?? null,
                                'description' => $descriptions[$subNoteId][$index] ?? null,
                            ]);
                            $totalCurrentYearAmount += $currentYearAmount;
                        }

                        // سال قبل
                        if ($previousYearAmount && $previousFinancialStatement) {
                            $previousRowData = [
                                'asset_note_id' => null,
                                'financial_statement_id' => $previousFinancialStatement->id,
                                'note_id' => $noteId,
                                'sub_note_id' => $subNoteIdVal,
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
                            CashInventory::updateOrCreate(
                                [
                                    'financial_statement_id' => $previousFinancialStatement->id,
                                    'note_id' => $noteId,
                                    'sub_note_id' => $subNoteIdVal,
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

            $remainingRecords = CashInventory::where('asset_note_id', $parentNote->id)->exists();
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
                CashInventory::insert($dataToInsert);
            }
        });
    }

    /**
     * ذخیره درآمدهای عملیاتی و آپدیت ProfitLossNote
     */
    public function storeOperationalRevenue(array $data, int $financialStatementId, Note $note): array
    {
        $config = $this->getChildModelConfig(null, OperationalRevenue::class);
        if (!$config) {
            return [
                'success' => false,
                'error' => 'پیکربندی مدل یافت نشد',
            ];
        }

        try {
            return DB::transaction(function () use ($data, $financialStatementId, $note, $config) {
                $profitLossNote = $config['parent_model']::firstOrCreate(
                    [
                        'financial_statement_id' => $financialStatementId,
                        'note_id' => $note->id,
                        'title' => 'درآمدهای عملیاتی',
                    ],
                    [
                        'current_year_amount' => 0,
                        'previous_year_amount' => null,
                        'is_restated' => 0,
                    ]
                );

                $config['child_model']::where($config['parent_foreign_key'], $profitLossNote->id)->delete();

                $totalCurrentYearAmount = 0;
                if (!empty($data['item_name'])) {
                    foreach ($data['item_name'] as $subNoteId => $items) {
                        foreach ($items as $index => $itemName) {
                            if ($itemName || !empty($data['current_year_amount'][$subNoteId][$index]) || !empty($data['current_year_quantity'][$subNoteId][$index]) || !empty($data['description'][$subNoteId][$index])) {
                                // حذف جداکننده‌های اعشار و تبدیل به عدد
                                $currentYearAmount = floatval(str_replace(',', '', $data['current_year_amount'][$subNoteId][$index] ?? 0));
                                $config['child_model']::create([
                                    'financial_statement_id' => $financialStatementId,
                                    $config['parent_foreign_key'] => $profitLossNote->id,
                                    'note_id' => $data['note_id'][$subNoteId][$index],
                                    'sub_note_id' => $data['sub_note_id'][$subNoteId][$index],
                                    'item_name' => $itemName ?: null,
                                    'current_year_quantity' => $data['current_year_quantity'][$subNoteId][$index] ?? null,
                                    'current_year_amount' => $currentYearAmount,
                                    'current_year_unit' => $data['current_year_unit'][$subNoteId][$index] ?? '',
                                    'description' => $data['description'][$subNoteId][$index] ?? null,
                                ]);
                                $totalCurrentYearAmount += $currentYearAmount;
                            }
                        }
                    }
                }

                $profitLossNote->update([
                    'current_year_amount' => $totalCurrentYearAmount,
                    'current_year_unit' => $data['current_year_unit'][key($data['item_name'])][0] ?? '',
                ]);

                return [
                    'success' => true,
                    'message' => 'درآمد عملیاتی با موفقیت ثبت شد',
                ];
            });
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
