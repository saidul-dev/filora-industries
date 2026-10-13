<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\BalanceSheetController;
use App\Http\Controllers\Admin\BomController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CapitalTransactionController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CollectionController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DayBookController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DesignationController;
use App\Http\Controllers\Admin\DueReportController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FundTransferController;
use App\Http\Controllers\Admin\IncomeController;
use App\Http\Controllers\Admin\InitialStockController;
use App\Http\Controllers\Admin\InternalConsumptionController;
use App\Http\Controllers\Admin\LeaveRequestController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\LedgerAccountController;
use App\Http\Controllers\Admin\MilestoneController;
use App\Http\Controllers\Admin\PartyController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PayrollRunController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductionOrderController;
use App\Http\Controllers\Admin\ProfitLossController;
use App\Http\Controllers\Admin\ProformaInvoiceController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\PurchaseRequisitionController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SalaryStructureController;
use App\Http\Controllers\Admin\SalesOrderController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SiteHealthController;
use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\StockReportController;
use App\Http\Controllers\Admin\StockTransferController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TrialBalanceController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CurrentSiteController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebsiteController;
use Illuminate\Support\Facades\Route;

// Public company website — About, Media, Career, Contact. Every vertical
// built on this base (property/hospital/resort/agency/restaurant, etc.)
// gets a public-facing company site out of the box.
Route::get('/', [WebsiteController::class, 'home'])->name('home');
Route::get('/about', [WebsiteController::class, 'about'])->name('about');
Route::get('/media', [WebsiteController::class, 'media'])->name('media');
Route::get('/career', [WebsiteController::class, 'career'])->name('career');
Route::get('/contact', [WebsiteController::class, 'contact'])->name('contact');
Route::post('/contact', [WebsiteController::class, 'submitContact'])->name('contact.submit');

// UI language switch — not an authenticated action, just a session preference.
Route::post('/language/{locale}', [LanguageController::class, 'switch'])->name('language.switch');

// Backend/admin panel — kept under /admin so the public company website
// can live at the root without colliding with these routes.
Route::prefix('admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware(['auth', 'verified', 'current-site'])->name('dashboard');

    Route::middleware(['auth', 'current-site'])->group(function () {
        Route::get('/select-site', [CurrentSiteController::class, 'select'])->name('sites.select');
        Route::post('/switch-site', [CurrentSiteController::class, 'switch'])->name('sites.switch');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        // Per-action permission checks live in each controller's middleware() method
        Route::resource('users', UserController::class)->except('show');
        Route::resource('roles', RoleController::class)->except('show');
        Route::resource('sites', SiteController::class)->except('show');
        Route::patch('/sites/{site}/toggle-status', [SiteController::class, 'toggleStatus'])->name('sites.toggle-status');

        // Inventory master data (global — not site-scoped; stock levels per Site come later)
        Route::resource('categories', CategoryController::class)->except('show');
        Route::patch('/categories/{category}/toggle-status', [CategoryController::class, 'toggleStatus'])->name('categories.toggle-status');
        Route::resource('brands', BrandController::class)->except('show');
        Route::patch('/brands/{brand}/toggle-status', [BrandController::class, 'toggleStatus'])->name('brands.toggle-status');
        Route::resource('units', UnitController::class)->except('show');
        Route::patch('/units/{unit}/toggle-status', [UnitController::class, 'toggleStatus'])->name('units.toggle-status');
        Route::resource('attributes', \App\Http\Controllers\Admin\AttributeController::class)->except('show');
        Route::resource('products', ProductController::class)->except('show');
        Route::patch('/products/{product}/toggle-status', [ProductController::class, 'toggleStatus'])->name('products.toggle-status');
        Route::get('/products/barcode', [ProductController::class, 'barcode'])->name('products.barcode');
        Route::get('/products/{product}/history', [ProductController::class, 'history'])->name('products.history');

        // Order-to-delivery cycle (docs/order-to-delivery.md): Sales Order
        // → Proforma Invoice → Requisition/Purchase/Production → Delivery.
        Route::resource('sales-orders', SalesOrderController::class)->parameters(['sales-orders' => 'salesOrder']);
        Route::post('/sales-orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm'])->name('sales-orders.confirm');
        Route::post('/sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->name('sales-orders.cancel');

        Route::post('/sales-orders/{salesOrder}/proforma-invoice', [ProformaInvoiceController::class, 'store'])->name('proforma-invoices.store');
        Route::resource('proforma-invoices', ProformaInvoiceController::class)->only(['index', 'show', 'edit', 'update'])
            ->parameters(['proforma-invoices' => 'proformaInvoice']);
        Route::post('/proforma-invoices/{proformaInvoice}/issue', [ProformaInvoiceController::class, 'issue'])->name('proforma-invoices.issue');
        Route::post('/proforma-invoices/{proformaInvoice}/lc', [ProformaInvoiceController::class, 'attachLc'])->name('proforma-invoices.lc');
        Route::get('/proforma-invoices/{proformaInvoice}/lc-file', [ProformaInvoiceController::class, 'lcFile'])->name('proforma-invoices.lc-file');
        Route::post('/proforma-invoices/{proformaInvoice}/ready', [ProformaInvoiceController::class, 'markReady'])->name('proforma-invoices.ready');
        Route::post('/proforma-invoices/{proformaInvoice}/close', [ProformaInvoiceController::class, 'close'])->name('proforma-invoices.close');
        Route::post('/proforma-invoices/{proformaInvoice}/cancel', [ProformaInvoiceController::class, 'cancel'])->name('proforma-invoices.cancel');
        Route::get('/proforma-invoices/{proformaInvoice}/print', [ProformaInvoiceController::class, 'print'])->name('proforma-invoices.print');

        Route::resource('purchase-requisitions', PurchaseRequisitionController::class)
            ->parameters(['purchase-requisitions' => 'purchaseRequisition']);
        Route::post('/purchase-requisitions/{purchaseRequisition}/approve', [PurchaseRequisitionController::class, 'approve'])->name('purchase-requisitions.approve');
        Route::post('/purchase-requisitions/{purchaseRequisition}/reject', [PurchaseRequisitionController::class, 'reject'])->name('purchase-requisitions.reject');

        Route::resource('purchases', PurchaseController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
        Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
        Route::get('/purchases/{purchase}/receive', [PurchaseController::class, 'receiveForm'])->name('purchases.receive');
        Route::post('/purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->name('purchases.receive.store');
        Route::get('/purchases/{purchase}/print', [PurchaseController::class, 'print'])->name('purchases.print');

        // Production — Bill of Materials per Manufactured product, and
        // Production Orders under a PI line (see docs/order-to-delivery.md).
        Route::get('/production/bom', [BomController::class, 'index'])->name('bom.index');
        Route::get('/production/bom/{product}', [BomController::class, 'edit'])->name('bom.edit');
        Route::put('/production/bom/{product}', [BomController::class, 'update'])->name('bom.update');
        Route::resource('production-orders', ProductionOrderController::class)->only(['index', 'create', 'store', 'show'])
            ->parameters(['production-orders' => 'productionOrder']);
        Route::post('/production-orders/{productionOrder}/start', [ProductionOrderController::class, 'start'])->name('production-orders.start');
        Route::post('/production-orders/{productionOrder}/complete', [ProductionOrderController::class, 'complete'])->name('production-orders.complete');
        Route::post('/production-orders/{productionOrder}/cancel', [ProductionOrderController::class, 'cancel'])->name('production-orders.cancel');

        Route::get('/stock/initial-stock', [InitialStockController::class, 'index'])->name('stock.initial.index');
        Route::post('/stock/initial-stock', [InitialStockController::class, 'store'])->name('stock.initial.store');
        Route::get('/stock/report', [StockReportController::class, 'index'])->name('stock.report');
        Route::get('/stock/adjustment', [StockAdjustmentController::class, 'index'])->name('stock.adjustment.index');
        Route::post('/stock/adjustment', [StockAdjustmentController::class, 'store'])->name('stock.adjustment.store');
        Route::get('/stock/internal-consumption', [InternalConsumptionController::class, 'index'])->name('stock.internal-consumption.index');
        Route::post('/stock/internal-consumption', [InternalConsumptionController::class, 'store'])->name('stock.internal-consumption.store');

        Route::get('/stock/transfers', [StockTransferController::class, 'index'])->name('stock.transfers.index');
        Route::get('/stock/transfers/create', [StockTransferController::class, 'create'])->name('stock.transfers.create');
        Route::post('/stock/transfers', [StockTransferController::class, 'store'])->name('stock.transfers.store');
        Route::get('/stock/transfers/{transfer}', [StockTransferController::class, 'show'])->name('stock.transfers.show');
        Route::post('/stock/transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('stock.transfers.receive');
        Route::post('/stock/transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock.transfers.cancel');

        Route::resource('parties', PartyController::class)->except('show');
        Route::patch('/parties/{party}/toggle-status', [PartyController::class, 'toggleStatus'])->name('parties.toggle-status');
        Route::get('/parties/{party}/ledger', [PartyController::class, 'ledger'])->name('parties.ledger');

        // HRM — Level 1.1 Employee Master (see docs/hrm-employee-management.md).
        Route::resource('departments', DepartmentController::class)->except('show');
        Route::patch('/departments/{department}/toggle-status', [DepartmentController::class, 'toggleStatus'])->name('departments.toggle-status');

        Route::resource('designations', DesignationController::class)->except('show');
        Route::patch('/designations/{designation}/toggle-status', [DesignationController::class, 'toggleStatus'])->name('designations.toggle-status');

        Route::resource('employees', EmployeeController::class)->except('show');
        Route::patch('/employees/{employee}/toggle-login', [EmployeeController::class, 'toggleLogin'])->name('employees.toggle-login');
        Route::delete('/employees/{employee}/attachments/{attachment}', [EmployeeController::class, 'destroyAttachment'])->name('employees.attachments.destroy');
        Route::put('/employees/{employee}/salary', [SalaryStructureController::class, 'update'])->name('employees.salary.update');

        // HRM — Level 1.2/1.3/1.4 Attendance, Leave, Payroll (see docs/hrm-employee-management.md).
        Route::get('/attendance/register', [AttendanceController::class, 'register'])->name('attendance.register');
        Route::post('/attendance/register', [AttendanceController::class, 'saveRegister'])->name('attendance.save-register');
        Route::get('/attendance/summary', [AttendanceController::class, 'summary'])->name('attendance.summary');
        Route::get('/attendance/my', [AttendanceController::class, 'my'])->name('attendance.my');
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])->name('attendance.check-in');
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('attendance.check-out');

        Route::resource('leave-types', LeaveTypeController::class)->except('show');
        Route::patch('/leave-types/{leaveType}/toggle-status', [LeaveTypeController::class, 'toggleStatus'])->name('leave-types.toggle-status');

        Route::resource('leave-requests', LeaveRequestController::class)->only(['index', 'store', 'destroy']);
        Route::patch('/leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
        Route::patch('/leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');

        Route::resource('payroll-runs', PayrollRunController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
        Route::patch('/payroll-runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])->name('payroll-runs.approve');
        Route::patch('/payroll-runs/{payrollRun}/items/{item}', [PayrollRunController::class, 'updateItem'])->name('payroll-runs.items.update');
        Route::get('/payroll-runs/items/{item}/payslip', [PayrollRunController::class, 'payslip'])->name('payroll-runs.items.payslip');

        // Project & Task Management — Project > Milestone > Task, with
        // Tasks also allowed to exist standalone (no project/milestone).
        Route::resource('projects', ProjectController::class);
        Route::post('/projects/{project}/milestones', [MilestoneController::class, 'store'])->name('projects.milestones.store');
        Route::patch('/projects/{project}/milestones/{milestone}', [MilestoneController::class, 'update'])->name('projects.milestones.update');
        Route::delete('/projects/{project}/milestones/{milestone}', [MilestoneController::class, 'destroy'])->name('projects.milestones.destroy');

        Route::resource('tasks', TaskController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::post('/tasks/{task}/comments', [TaskController::class, 'storeComment'])->name('tasks.comments.store');
        Route::delete('/tasks/{task}/comments/{comment}', [TaskController::class, 'destroyComment'])->name('tasks.comments.destroy');
        Route::post('/tasks/{task}/attachments', [TaskController::class, 'storeAttachment'])->name('tasks.attachments.store');
        Route::delete('/tasks/{task}/attachments/{attachment}', [TaskController::class, 'destroyAttachment'])->name('tasks.attachments.destroy');
        Route::post('/tasks/{task}/time-logs', [TaskController::class, 'storeTimeLog'])->name('tasks.time-logs.store');
        Route::delete('/tasks/{task}/time-logs/{timeLog}', [TaskController::class, 'destroyTimeLog'])->name('tasks.time-logs.destroy');
        Route::patch('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');

        // Accounting foundation — see docs/accounting-foundation.md. Only
        // Bank Accounts has a CRUD screen in Phase 1; every other
        // ledger_accounts group is system-seeded.
        Route::resource('bank-accounts', LedgerAccountController::class)->except('show')
            ->parameters(['bank-accounts' => 'bankAccount']);
        Route::patch('/bank-accounts/{bankAccount}/toggle-status', [LedgerAccountController::class, 'toggleStatus'])->name('bank-accounts.toggle-status');
        Route::get('/bank-accounts/{bankAccount}/ledger', [LedgerAccountController::class, 'ledger'])->name('bank-accounts.ledger');
        Route::resource('payments', PaymentController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('/payments/{payment}/print', [PaymentController::class, 'print'])->name('payments.print');
        Route::resource('collections', CollectionController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('/collections/{collection}/print', [CollectionController::class, 'print'])->name('collections.print');
        Route::resource('expenses', ExpenseController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('/expenses/{expense}/print', [ExpenseController::class, 'print'])->name('expenses.print');
        Route::resource('incomes', IncomeController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('/incomes/{income}/print', [IncomeController::class, 'print'])->name('incomes.print');
        Route::resource('fund-transfers', FundTransferController::class)->only(['index', 'create', 'store', 'show'])
            ->parameters(['fund-transfers' => 'fundTransfer']);
        Route::get('/fund-transfers/{fundTransfer}/print', [FundTransferController::class, 'print'])->name('fund-transfers.print');
        Route::resource('capital-transactions', CapitalTransactionController::class)->only(['index', 'create', 'store', 'show'])
            ->parameters(['capital-transactions' => 'capitalTransaction']);
        Route::get('/capital-transactions/{capitalTransaction}/print', [CapitalTransactionController::class, 'print'])->name('capital-transactions.print');
        Route::get('/day-book', [DayBookController::class, 'index'])->name('day-book.index');
        Route::get('/trial-balance', [TrialBalanceController::class, 'index'])->name('trial-balance.index');
        Route::get('/balance-sheet', [BalanceSheetController::class, 'index'])->name('balance-sheet.index');
        Route::get('/profit-loss', [ProfitLossController::class, 'index'])->name('profit-loss.index');
        Route::get('/due-report', [DueReportController::class, 'index'])->name('due-report.index');

        Route::get('/contact-messages', [ContactMessageController::class, 'index'])->name('contact-messages.index');
        Route::get('/contact-messages/{contactMessage}', [ContactMessageController::class, 'show'])->name('contact-messages.show');
        Route::delete('/contact-messages/{contactMessage}', [ContactMessageController::class, 'destroy'])->name('contact-messages.destroy');

        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::get('/settings/website', [SettingController::class, 'editWebsite'])->name('settings.website.edit');
        Route::put('/settings/website', [SettingController::class, 'updateWebsite'])->name('settings.website.update');
        Route::get('/settings/attendance', [SettingController::class, 'editAttendance'])->name('settings.attendance.edit');
        Route::put('/settings/attendance', [SettingController::class, 'updateAttendance'])->name('settings.attendance.update');

        Route::post('/site-health/refresh', [SiteHealthController::class, 'refresh'])->name('site-health.refresh');
    });
});

require __DIR__.'/auth.php';
