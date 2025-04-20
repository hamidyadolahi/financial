<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Note;
use App\Models\Tenant\OperationalRevenue;
use App\Models\Tenant\ProfitLossNote;
use App\Services\Financial\FinancialNoteService;
use App\Services\Financial\Table6020SelectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationalRevenueController extends Controller
{
    protected $financialNoteService;
    protected $table6020SelectionService;

    public function __construct(
        FinancialNoteService $financialNoteService,
        Table6020SelectionService $table6020SelectionService
    ) {
        $this->financialNoteService = $financialNoteService;
        $this->table6020SelectionService = $table6020SelectionService;
    }

    public function index()
    {
        return redirect()->route('profit-loss-note.index');
    }

    public function create()
    {
        $salemaliId = activeSalemali();
        if (!$salemaliId) {
            alert()->warning('ابتدا باید یک صورت مالی فعال ایجاد کنید.', 'هشدار')->persistent("تأیید");
            return redirect()->route('profit-loss-note.index');
        }

        $note = $this->financialNoteService->findNoteByTitle('درآمدهای عملیاتی');
        if (!$note) {
            alert()->error('یادداشت "درآمدهای عملیاتی" یافت نشد.', 'خطا')->persistent("تأیید");
            return redirect()->route('profit-loss-note.index');
        }

        $duplicateCheck = $this->financialNoteService->preventDuplicateCreation(
            modelClass: OperationalRevenue::class,
            redirectRoute: 'profit-loss-note.index',
            warningMessage: 'داده‌ای برای سال مالی فعال وجود داره. لطفاً از ویرایش استفاده کنید.'
        );
        if ($duplicateCheck) {
            return $duplicateCheck;
        }

        $formData = $this->financialNoteService->prepareFormData(
            modelClass: OperationalRevenue::class,
            note: $note,
            parentNote: null,
            actionRoute: route('operational-revenue.store'),
            title: 'افزودن درآمدهای عملیاتی',
            parentModelClass: ProfitLossNote::class,
            parentForeignKey: 'profit_loss_note_id'
        );

        if (isset($formData['error'])) {
            alert()->error($formData['error'], 'خطا')->persistent("تأیید");
            return redirect()->route('profit-loss-note.index');
        }

        return view('tenant.operational-revenue.create', $formData);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'financial_statement_id' => 'required|exists:financial_statements,id',
            'from' => 'nullable|in:operational-revenue,operational-revenue-edit',
            'note_id' => 'required|array',
            'note_id.*.*' => 'required|integer|exists:notes,id',
            'sub_note_id' => 'required|array',
            'sub_note_id.*.*' => 'required|integer|exists:notes,id',
            'item_name' => 'nullable|array',
            'item_name.*.*' => 'nullable|string|max:255',
            'current_year_quantity' => 'nullable|array',
            'current_year_quantity.*.*' => 'nullable|string|max:255',
            'current_year_amount' => 'nullable|array',
            'current_year_amount.*.*' => 'nullable|min:0',
            'previous_year_quantity' => 'nullable|array',
            'previous_year_quantity.*.*' => 'nullable|string|max:255',
            'previous_year_amount' => 'nullable|array',
            'previous_year_amount.*.*' => 'nullable|min:0',
            'description' => 'nullable|array',
            'description.*.*' => 'nullable|string|max:255',
            'is_restated' => 'nullable|boolean',
        ]);

        $note = $this->financialNoteService->findNoteByTitle('درآمدهای عملیاتی');
        if (!$note) {
            return back()->withErrors(['error' => 'یادداشت "درآمدهای عملیاتی" یافت نشد.']);
        }

        DB::beginTransaction();
        try {
            $parentNote = ProfitLossNote::firstOrCreate(
                [
                    'financial_statement_id' => $data['financial_statement_id'],
                    'note_id' => $note->id,
                ],
                [
                    'title' => $note->title,
                    'current_year_amount' => 0,
                    'previous_year_amount' => 0,
                ]
            );

            // ذخیره ردیف‌های دستی
            $result = $this->financialNoteService->storeOperationalRevenue(
                data: $data,
                financialStatementId: $data['financial_statement_id'],
                note: $note
            );

            if (!$result['success']) {
                throw new \Exception($result['error']);
            }

            DB::commit();
            alert()->success('درآمد عملیاتی با موفقیت ثبت شد', 'پیغام سیستم')->persistent("تأیید");
            return redirect()->route('profit-loss-note.index');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'خطایی رخ داد: ' . $e->getMessage()]);
        }
    }

    public function edit(ProfitLossNote $profit_loss_note_id)
    {
        $note = Note::findOrFail($profit_loss_note_id->note_id);
        if ($profit_loss_note_id->financial_statement_id != activeSalemali()) {
            alert()->error('سال مالی مطابقت ندارد.', 'خطا')->persistent("تأیید");
            return redirect()->route('profit-loss-note.index');
        }

        $formData = $this->financialNoteService->prepareFormData(
            modelClass: OperationalRevenue::class,
            note: $note,
            parentNote: $profit_loss_note_id,
            actionRoute: route('operational-revenue.update', $profit_loss_note_id->id),
            title: 'ویرایش درآمدهای عملیاتی',
            parentModelClass: ProfitLossNote::class,
            parentForeignKey: 'profit_loss_note_id'
        );
        

        if (isset($formData['error'])) {
            alert()->error($formData['error'], 'خطا')->persistent("تأیید");
            return redirect()->route('profit-loss-note.index');
        }

        return view('tenant.operational-revenue.create', $formData);
    }

    public function update(Request $request, ProfitLossNote $profit_loss_note_id)
    {
        if ($profit_loss_note_id->financial_statement_id != activeSalemali()) {
            return back()->withErrors(['error' => 'سال مالی مطابقت ندارد']);
        }

        $note = Note::findOrFail($profit_loss_note_id->note_id);
        if ($note->title !== 'درآمدهای عملیاتی') {
            return back()->withErrors(['error' => 'یادداشت "درآمدهای عملیاتی" مطابقت ندارد']);
        }

        $data = $request->validate([
            'financial_statement_id' => 'required|exists:financial_statements,id',
            'item_name' => 'nullable|array',
            'item_name.*.*' => 'nullable|string|max:255',
            'current_year_quantity' => 'nullable|array',
            'current_year_quantity.*.*' => 'nullable|string|max:255',
            'current_year_amount' => 'nullable|array',
            'current_year_amount.*.*' => 'nullable',
            'previous_year_quantity' => 'nullable|array',
            'previous_year_quantity.*.*' => 'nullable|string|max:255',
            'previous_year_amount' => 'nullable|array',
            'previous_year_amount.*.*' => 'nullable',
            'note_id' => 'required|array',
            'note_id.*.*' => 'required|integer|exists:notes,id',
            'sub_note_id' => 'required|array',
            'sub_note_id.*.*' => 'required|integer|exists:notes,id',
            'operational_revenue_id' => 'nullable|array',
            'operational_revenue_id.*.*' => 'nullable|integer',
            'profit_loss_note_id' => 'required|array',
            'profit_loss_note_id.*.*' => 'required|integer',
            'description' => 'nullable|array',
            'description.*.*' => 'nullable|string|max:255',
            'is_restated' => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            // حذف ردیف‌های قدیمی که دیگر در فرم وجود ندارند
            $submittedIds = [];
            if (isset($data['operational_revenue_id'])) {
                foreach ($data['operational_revenue_id'] as $subNoteId => $ids) {
                    $submittedIds = array_merge($submittedIds, array_filter($ids));
                }
            }
            OperationalRevenue::where('profit_loss_note_id', $profit_loss_note_id->id)
                ->whereNotIn('id', $submittedIds)
                ->whereNull('table6020_id')
                ->delete();

            // آپدیت ردیف‌های دستی
            $result = $this->financialNoteService->storeOperationalRevenue(
                data: $data,
                financialStatementId: $data['financial_statement_id'],
                note: $note
            );

            if (!$result['success']) {
                throw new \Exception($result['error']);
            }

            DB::commit();
            alert()->success('درآمد عملیاتی با موفقیت به‌روزرسانی شد', 'پیغام سیستم')->persistent("تأیید");
            return redirect()->route('profit-loss-note.index');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'خطایی رخ داد: ' . $e->getMessage()]);
        }
    }

    public function storeSelection(Request $request)
    {
        $data = $request->validate([
            'financial_statement_id' => 'required|exists:financial_statements,id',
            'from' => 'required|string|in:operational-revenue,operational-revenue-edit',
            'note_id' => 'required|array',
            'note_id.*' => 'required|integer',
            'sub_note_id' => 'required|array',
            'sub_note_id.*' => 'required|integer',
            'model_class' => 'required|string|in:App\Models\Tenant\CashInventory,App\Models\Tenant\OperationalRevenue',
            'selected_items' => 'nullable|array',
            'selected_items.*' => 'integer',
            'detail_titles' => 'required_with:selected_items|array',
            'detail_titles.*' => 'required_with:selected_items|string',
            'final_balances' => 'required_with:selected_items|array',
            'final_balances.*' => 'required_with:selected_items|numeric',
            'previous_year_amount' => 'nullable|array',
            'previous_year_amount.*' => 'nullable|numeric',
            'table6020_general_code' => 'nullable|array',
            'table6020_general_code.*' => 'nullable|string',
            'table6020_specific_code' => 'nullable|array',
            'table6020_specific_code.*' => 'nullable|string',
            'table6020_detail_code' => 'nullable|array',
            'table6020_detail_code.*' => 'nullable|string',
            'description' => 'nullable|array',
            'description.*' => 'nullable|array',
            'description.*.*' => 'nullable|string',
            'is_restated' => 'nullable|boolean',
        ]);


        try {
            // گرفتن پیکربندی مدل
            $config = $this->financialNoteService->getChildModelConfig(null, $data['model_class']);
            if (!$config) {
                throw new \Exception('پیکربندی مدل یافت نشد.');
            }

            // چک کردن وجود selected_items
            if (empty($data['selected_items'])) {
                throw new \Exception('حداقل یک آیتم باید انتخاب شود.');
            }

            $result = $this->table6020SelectionService->storeSelection(
                $data,
                $data['model_class'],
                $config['parent_foreign_key']
            );
            alert()->success($result['message'] ?? 'انتخاب با موفقیت ثبت شد.', 'پیغام سیستم')->persistent('تأیید');
            return redirect($result['redirect']);
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'خطایی رخ داد: ' . $e->getMessage()]);
        }
    }
}
