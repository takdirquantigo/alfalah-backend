<?php
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DayCloseController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ItemsController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Middleware\AuthenticateWithToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;



Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/signup', [AuthController::class, 'signup']);
});

Route::post('/customer-auth/login', [CustomerAuthController::class, 'login']);

Route::middleware(['auth.customer'])->prefix('customer-portal')->group(function () {
    Route::get('/dashboard', [CustomerPortalController::class, 'dashboard']);
    Route::get('/transactions/{id}', [CustomerPortalController::class, 'transactionDetail']);
    Route::get('/transactions', [CustomerPortalController::class, 'transactions']);
    Route::get('/due-bills', [CustomerPortalController::class, 'dueBills']);
});
Route::prefix('company')->group(function () {
    Route::post('/add', [CompanyController::class, 'addCompany']);
});


Route::middleware([AuthenticateWithToken::class])->prefix('day-close')->group(function () {
    Route::get('/today', [DayCloseController::class, 'today']);
    Route::get('/', [DayCloseController::class, 'index']);
    Route::post('/', [DayCloseController::class, 'close']);
});
Route::middleware([AuthenticateWithToken::class])->prefix('expense')->group(function () {
    Route::get('/states', [ExpenseController::class, 'getStates']);
    Route::post('/', [ExpenseController::class, 'addExpense']);
    Route::get('/', [ExpenseController::class, 'getExpense']);
    Route::get('/{id}', [ExpenseController::class, 'singleExpense']);
    Route::post('/{id}', [ExpenseController::class, 'updateExpense']);
    Route::put('/{id}', [ExpenseController::class, 'updateExpense']);
    Route::delete('/{id}', [ExpenseController::class, 'deleteExpense']);
});
Route::middleware([AuthenticateWithToken::class])->prefix('customers')->group(function () {
    Route::post('/', [CustomersController::class, 'addCustomer']);
    Route::put('/', [CustomersController::class, 'getCustomerWithSum']);
    Route::put('/recentcustomers', [CustomersController::class, 'lastCustomers']);
    Route::post('/{id}', [CustomersController::class, 'updateCustomer']);
    Route::delete('/{id}', [CustomersController::class, 'deleteCustomer']);

    Route::get('/details/{id}', [CustomersController::class, 'details']);
    Route::get('/all-customers', [CustomersController::class, 'allCustomers']);
    Route::get('/dashboard', [CustomersController::class, 'dashboard']);
    Route::get('/dashboard/daily-sales', [CustomersController::class, 'dailySales']);
    Route::get('/dashboard/daily-dues', [CustomersController::class, 'dailyDues']);
    Route::get('/inactive', [CustomersController::class,'inactiveCustomers']);
    Route::get('/due-balances', [CustomersController::class, 'dueBalances']);
    Route::get('/cylinder-dues', [CustomersController::class, 'cylinderDues']);
});

Route::middleware([AuthenticateWithToken::class])->prefix('products')->group(function () {
    Route::post('/', [ProductsController::class, 'addProduct']);
    Route::put('/', [ProductsController::class, 'getProducts']);
    Route::get('/{id}', [ProductsController::class, 'singleProduct']);
    Route::put('/{id}', [ProductsController::class, 'updateProduct']);
    Route::delete('/{id}', [ProductsController::class, 'deleteProduct']);
    Route::get('/laststock', [ProductsController::class, 'getLastStock']);
    Route::get('/stock/states', [ProductsController::class, 'getStates']);
    Route::get('/stock/update', [ProductsController::class, 'getUpdatedStock']);
    

    Route::get('/stock/states', [ProductsController::class, 'getStates']);
});

Route::middleware([AuthenticateWithToken::class])->prefix('sales')->group(function () {
    Route::post('/', [SalesController::class, 'newSales']);
    Route::post('/update', [SalesController::class, 'updateSale']);
    Route::put('/', [SalesController::class, 'getSalesList']);
    Route::put('/collectionList', [SalesController::class, 'getCollectionList']);
    Route::get('/lastsale', [SalesController::class, 'getLastsale']);
    Route::put('/compare', [SalesController::class, 'getCompares']);
    Route::get('/stock', [SalesController::class, 'getStock']);
    Route::get('/recentSales', [SalesController::class, 'getRecent']);
    Route::put('/profittoday', [SalesController::class, 'getProfittoday']);
    Route::get('/todaysales', [SalesController::class, 'getTodaySales']);
    Route::get('/mothsales', [SalesController::class, 'monthsales']);
    Route::get('/cash', [SalesController::class, 'cash']);
    Route::put('/{id}', [SalesController::class, 'editSales']);
    Route::delete('/{id}', [SalesController::class, 'deleteSale']);

    Route::get('/details/{id}', [SalesController::class, 'details']);
    Route::post('/collection', [SalesController::class, 'collection']);
    Route::post('/empty-return', [SalesController::class, 'emptyReturn']);
});
Route::middleware([])->prefix('items')->group(function () {
    Route::post('/', [ItemsController::class, 'store']);
    Route::get('/', [ItemsController::class, 'index']);
    Route::put('/{id}', [ItemsController::class, 'update']);
    Route::delete('/{id}', [ItemsController::class, 'destroy']);
});
Route::middleware([AuthenticateWithToken::class])->prefix('customer')->group(function () {
    Route::get('/states', [SalesController::class, 'customerStates']);
});

Route::middleware([AuthenticateWithToken::class])->prefix('analysis')->group(function () {
    Route::get('/overview', [AnalysisController::class, 'overview']);
});

