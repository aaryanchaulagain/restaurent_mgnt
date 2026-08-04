<?php

use App\Http\Controllers\Api\Admin\AdminBranchController;
use App\Http\Controllers\Api\Admin\AdminDisputeController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminPaymentAccountController;
use App\Http\Controllers\Api\Admin\AdminPaymentController;
use App\Http\Controllers\Api\Admin\AdminPaymentWebhookController;
use App\Http\Controllers\Api\Admin\AdminRefundController;
use App\Http\Controllers\Api\Admin\AdminRestaurantApplicationController;
use App\Http\Controllers\Api\Admin\AdminRestaurantController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Partner\PartnerApplicationController;
use App\Http\Controllers\Api\Business\BusinessBranchController;
use App\Http\Controllers\Api\Cart\CartController;
use App\Http\Controllers\Api\Checkout\CheckoutController;
use App\Http\Controllers\Api\Customer\CustomerAddressController;
use App\Http\Controllers\Api\Customer\CustomerBranchRecommendationController;
use App\Http\Controllers\Api\Order\CustomerOrderController;
use App\Http\Controllers\Api\Order\RestaurantOrderController;
use App\Http\Controllers\Api\Payment\CustomerPaymentController;
use App\Http\Controllers\Api\Public\PublicRestaurantController;
use App\Http\Controllers\Api\Public\PublicBusinessController;
use App\Http\Controllers\Api\Public\PublicBranchRecommendationController;
use App\Http\Controllers\Api\Restaurant\RestaurantHoursController;
use App\Http\Controllers\Api\Restaurant\RestaurantInventoryController;
use App\Http\Controllers\Api\Restaurant\RestaurantMediaController;
use App\Http\Controllers\Api\Restaurant\RestaurantMenuController;
use App\Http\Controllers\Api\Restaurant\RestaurantOfferController;
use App\Http\Controllers\Api\Restaurant\RestaurantPaymentAccountController;
use App\Http\Controllers\Api\Restaurant\RestaurantPaymentController;
use App\Http\Controllers\Api\Restaurant\RestaurantProfileController;
use App\Http\Controllers\Api\Restaurant\RestaurantServiceAreaController;
use App\Http\Controllers\Api\Restaurant\RestaurantStaffController;
use App\Http\Controllers\Api\Restaurant\RestaurantAuthorizationController;
use App\Http\Controllers\Api\Webhooks\StripeWebhookController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureMfaSatisfied;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRestaurantAccess;
use App\Http\Middleware\EnsureRole;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::post('/webhooks/stripe', StripeWebhookController::class);

    Route::prefix('public')->group(function (): void {
        Route::get('/restaurants', [PublicRestaurantController::class, 'index']);
        Route::get('/cuisines', [PublicRestaurantController::class, 'cuisines']);
        Route::get('/restaurants/{slug}', [PublicRestaurantController::class, 'show']);
        Route::get('/restaurants/{slug}/menu', [PublicRestaurantController::class, 'menu']);
        Route::get('/platform-restaurant', [PublicRestaurantController::class, 'platformRestaurant']);

        Route::get('/businesses/{businessSlug}', [PublicBusinessController::class, 'show']);
        Route::get('/businesses/{businessSlug}/branches', [PublicBusinessController::class, 'branches']);
        Route::get('/businesses/{businessSlug}/branches/{branchPublicId}', [PublicBusinessController::class, 'showBranch']);
        Route::get('/businesses/{businessSlug}/branches/{branchPublicId}/menu', [PublicBusinessController::class, 'branchMenu']);
        Route::post('/businesses/{businessSlug}/branch-recommendations', [PublicBranchRecommendationController::class, 'store'])
            ->middleware('throttle:30,1');
    });

    Route::get('/branch-invitations/{token}', [\App\Http\Controllers\Api\Public\PublicBranchInvitationController::class, 'show'])
        ->middleware('throttle:30,1');
    Route::post('/branch-invitations/{token}/accept', [\App\Http\Controllers\Api\Public\PublicBranchInvitationController::class, 'accept'])
        ->middleware('throttle:20,1');

    Route::prefix('cart')->middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsureEmailIsVerified::class])->group(function (): void {
        Route::get('/', [CartController::class, 'show']);
        Route::post('/items', [CartController::class, 'storeItem']);
        Route::patch('/items/{publicId}', [CartController::class, 'updateItem']);
        Route::delete('/items/{publicId}', [CartController::class, 'destroyItem']);
        Route::delete('/', [CartController::class, 'destroy']);
        Route::post('/replace-restaurant', [CartController::class, 'replaceRestaurant']);
        Route::post('/validate', [CartController::class, 'validateCart']);
        Route::post('/merge', [CartController::class, 'merge']);
    });

    Route::post('/checkout/quote', [CheckoutController::class, 'quote'])
        ->middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsureEmailIsVerified::class]);

    Route::post('/orders', [CustomerOrderController::class, 'store'])
        ->middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsureEmailIsVerified::class, EnsureRole::class.':customer']);
    Route::get('/guest/orders/{orderNumber}', [CustomerOrderController::class, 'guestShow']);

    Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsureEmailIsVerified::class, EnsureRole::class.':customer'])
        ->prefix('orders')
        ->group(function (): void {
            Route::get('/', [CustomerOrderController::class, 'index']);
            Route::get('/{publicId}', [CustomerOrderController::class, 'show']);
            Route::post('/{publicId}/cancel', [CustomerOrderController::class, 'cancel']);
            Route::get('/{publicId}/payment', [CustomerPaymentController::class, 'show'])
                ->middleware(EnsurePermission::class.':view_own_order_payment');
            Route::post('/{publicId}/payment/retry', [CustomerPaymentController::class, 'retry'])
                ->middleware(EnsurePermission::class.':retry_own_payment');
        });

    Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsureEmailIsVerified::class, EnsureRole::class.':customer'])
        ->prefix('customer/addresses')
        ->group(function (): void {
            Route::get('/', [CustomerAddressController::class, 'index']);
            Route::post('/', [CustomerAddressController::class, 'store']);
            Route::patch('/{publicId}', [CustomerAddressController::class, 'update']);
            Route::delete('/{publicId}', [CustomerAddressController::class, 'destroy']);
        });

    Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsureEmailIsVerified::class, EnsureRole::class.':customer'])
        ->prefix('customer')
        ->group(function (): void {
            Route::post('/businesses/{businessSlug}/branch-recommendations', [CustomerBranchRecommendationController::class, 'store'])
                ->middleware('throttle:60,1');
        });
});

Route::prefix('auth')->group(function (): void {
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->name('auth.email.verify');
    });

    Route::post('/mfa/challenge', [AuthController::class, 'mfaChallenge'])->middleware('throttle:10,1');
    Route::post('/mfa/recovery', [AuthController::class, 'mfaRecovery'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/me', [AuthController::class, 'me'])->middleware([
            EnsureAccountIsActive::class,
        ]);

        Route::post('/email/verification-notification', [AuthController::class, 'sendVerification'])
            ->middleware('throttle:6,1');

        Route::middleware([EnsureAccountIsActive::class, EnsureEmailIsVerified::class])->group(function (): void {
            Route::post('/change-password', [AuthController::class, 'changePassword']);
            Route::get('/sessions', [AuthController::class, 'sessions']);
            Route::delete('/sessions/{sessionId}', [AuthController::class, 'revokeSession']);
            Route::delete('/sessions', [AuthController::class, 'revokeOtherSessions']);

            Route::post('/mfa/setup', [AuthController::class, 'mfaSetup']);
            Route::post('/mfa/confirm', [AuthController::class, 'mfaConfirm']);
            Route::post('/mfa/regenerate-recovery-codes', [AuthController::class, 'mfaRegenerateRecoveryCodes']);
            Route::delete('/mfa', [AuthController::class, 'mfaDisable']);
        });
    });
});

Route::prefix('v1')->middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsureEmailIsVerified::class])->group(function (): void {
    Route::middleware([EnsureRole::class.':customer'])->get('/customer/ping', function () {
        return ApiResponse::success(data: ['portal' => 'customer']);
    });

    Route::middleware([
        EnsureRole::class.':restaurant_owner,restaurant_manager,restaurant_staff,super_admin',
        EnsureRestaurantAccess::class,
        EnsurePermission::class.':view_restaurant_dashboard',
    ])->get('/restaurant/ping', function () {
        return ApiResponse::success(data: [
            'portal' => 'restaurant',
            'restaurant_id' => request()->attributes->get('restaurant_id'),
            'restaurant_public_id' => request()->attributes->get('restaurant_public_id'),
            'business_id' => request()->attributes->get('business_id'),
            'business_public_id' => request()->attributes->get('business_public_id'),
            'branch_id' => request()->attributes->get('branch_id'),
            'branch_public_id' => request()->attributes->get('branch_public_id'),
        ]);
    });

    Route::middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
        EnsurePermission::class.':view_super_admin_dashboard',
    ])->get('/admin/ping', function () {
        return ApiResponse::success(data: ['portal' => 'admin']);
    });

    Route::prefix('partner/applications')->group(function (): void {
        Route::post('/', [PartnerApplicationController::class, 'store']);
        Route::get('/current', [PartnerApplicationController::class, 'current']);
        Route::get('/{publicId}', [PartnerApplicationController::class, 'show']);
        Route::patch('/{publicId}', [PartnerApplicationController::class, 'update']);
        Route::post('/{publicId}/submit', [PartnerApplicationController::class, 'submit']);
        Route::post('/{publicId}/resubmit', [PartnerApplicationController::class, 'resubmit']);
        Route::post('/{publicId}/withdraw', [PartnerApplicationController::class, 'withdraw']);
        Route::post('/{publicId}/documents', [PartnerApplicationController::class, 'uploadDocument']);
        Route::get('/{publicId}/documents', [PartnerApplicationController::class, 'listDocuments']);
        Route::delete('/{publicId}/documents/{documentId}', [PartnerApplicationController::class, 'deleteDocument']);
        Route::get('/{publicId}/documents/{documentId}/download', [PartnerApplicationController::class, 'downloadDocument']);
        Route::post('/{publicId}/commission-agreement/accept', [PartnerApplicationController::class, 'acceptCommission']);
    });

    Route::prefix('admin/restaurant-applications')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
    ])->group(function (): void {
        Route::get('/', [AdminRestaurantApplicationController::class, 'index']);
        Route::get('/{publicId}', [AdminRestaurantApplicationController::class, 'show']);
        Route::post('/{publicId}/start-review', [AdminRestaurantApplicationController::class, 'startReview']);
        Route::post('/{publicId}/request-changes', [AdminRestaurantApplicationController::class, 'requestChanges']);
        Route::post('/{publicId}/approve', [AdminRestaurantApplicationController::class, 'approve']);
        Route::post('/{publicId}/reject', [AdminRestaurantApplicationController::class, 'reject']);
        Route::post('/{publicId}/assign-reviewer', [AdminRestaurantApplicationController::class, 'assignReviewer']);
        Route::post('/{publicId}/notes', [AdminRestaurantApplicationController::class, 'addNote']);
        Route::post('/{publicId}/documents/{documentId}/verify', [AdminRestaurantApplicationController::class, 'verifyDocument']);
        Route::post('/{publicId}/documents/{documentId}/reject', [AdminRestaurantApplicationController::class, 'rejectDocument']);
        Route::get('/{publicId}/documents/{documentId}/download', [AdminRestaurantApplicationController::class, 'downloadDocument']);
        Route::get('/{publicId}/commission-agreement', [AdminRestaurantApplicationController::class, 'getCommission']);
        Route::post('/{publicId}/commission-agreement', [AdminRestaurantApplicationController::class, 'storeCommission']);
        Route::patch('/{publicId}/commission-agreement', [AdminRestaurantApplicationController::class, 'updateCommission']);
    });

    Route::prefix('admin/orders')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
    ])->group(function (): void {
        Route::get('/', [AdminOrderController::class, 'index'])->middleware(EnsurePermission::class.':view_all_platform_orders');
        Route::get('/{publicId}', [AdminOrderController::class, 'show'])->middleware(EnsurePermission::class.':view_platform_order_details');
    });

    Route::prefix('admin/payments')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
    ])->group(function (): void {
        Route::get('/', [AdminPaymentController::class, 'index'])
            ->middleware(EnsurePermission::class.':view_all_platform_payments');
        Route::get('/{publicId}', [AdminPaymentController::class, 'show'])
            ->middleware(EnsurePermission::class.':view_platform_payment_details');
        Route::post('/{publicId}/refunds', [AdminPaymentController::class, 'createRefund']);
    });

    Route::prefix('admin/refunds')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
        EnsurePermission::class.':view_all_platform_payments',
    ])->group(function (): void {
        Route::get('/', [AdminRefundController::class, 'index']);
        Route::get('/{publicId}', [AdminRefundController::class, 'show']);
    });

    Route::prefix('admin/disputes')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
        EnsurePermission::class.':view_payment_disputes',
    ])->group(function (): void {
        Route::get('/', [AdminDisputeController::class, 'index']);
        Route::get('/{publicId}', [AdminDisputeController::class, 'show']);
    });

    Route::prefix('admin/payment-webhooks')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
        EnsurePermission::class.':retry_failed_webhook',
    ])->group(function (): void {
        Route::post('/{eventPublicId}/retry', [AdminPaymentWebhookController::class, 'retry']);
    });

    Route::prefix('admin/payment-accounts')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
        EnsurePermission::class.':manage_payment_accounts',
    ])->group(function (): void {
        Route::get('/', [AdminPaymentAccountController::class, 'index']);
        Route::get('/{restaurantPublicId}', [AdminPaymentAccountController::class, 'show']);
        Route::post('/{restaurantPublicId}/refresh', [AdminPaymentAccountController::class, 'refresh']);
        Route::post('/{restaurantPublicId}/disable-online-payments', [AdminPaymentAccountController::class, 'disableOnlinePayments']);
    });

    Route::prefix('admin/restaurants')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
        EnsurePermission::class.':manage_restaurants',
    ])->group(function (): void {
        Route::get('/', [AdminRestaurantController::class, 'index']);
        Route::post('/provision', [AdminRestaurantController::class, 'provision']);
        Route::get('/{publicId}', [AdminRestaurantController::class, 'show']);
        Route::patch('/{publicId}', [AdminRestaurantController::class, 'update']);
        Route::delete('/{publicId}', [AdminRestaurantController::class, 'destroy']);
        Route::post('/{publicId}/owners', [AdminRestaurantController::class, 'addOwner']);
        Route::delete('/{publicId}/owners/{userId}', [AdminRestaurantController::class, 'removeOwner']);
    });

    Route::prefix('admin/branches')->middleware([
        EnsureRole::class.':super_admin',
        EnsureMfaSatisfied::class,
        EnsurePermission::class.':manage_restaurants',
    ])->group(function (): void {
        Route::post('/{branch}/suspend', [AdminBranchController::class, 'suspend']);
        Route::post('/{branch}/unsuspend', [AdminBranchController::class, 'unsuspend']);
    });

    Route::prefix('businesses')->middleware([
        EnsureRole::class.':restaurant_owner,restaurant_manager,restaurant_staff,super_admin',
    ])->group(function (): void {
        Route::get('/context', [BusinessBranchController::class, 'context']);
        Route::get('/', [BusinessBranchController::class, 'listBusinesses']);
        Route::get('/{business}', [BusinessBranchController::class, 'showBusiness']);
        Route::get('/{business}/branches', [BusinessBranchController::class, 'index']);
        Route::post('/{business}/branches', [BusinessBranchController::class, 'store']);
        Route::get('/{business}/branches/{branch}', [BusinessBranchController::class, 'show']);
        Route::patch('/{business}/branches/{branch}', [BusinessBranchController::class, 'update']);
        Route::post('/{business}/branches/{branch}/pause', [BusinessBranchController::class, 'pause']);
        Route::post('/{business}/branches/{branch}/activate', [BusinessBranchController::class, 'activate']);
        Route::post('/{business}/branches/{branch}/deactivate', [BusinessBranchController::class, 'deactivate']);

        Route::get('/{business}/users', [BusinessBranchController::class, 'businessUsers']);
        Route::post('/{business}/users', [BusinessBranchController::class, 'storeBusinessUser']);
        Route::delete('/{business}/users/{userId}', [BusinessBranchController::class, 'destroyBusinessUser']);

        Route::get('/{business}/branches/{branch}/users', [BusinessBranchController::class, 'branchUsers']);
        Route::post('/{business}/branches/{branch}/users', [BusinessBranchController::class, 'storeBranchUser']);
        Route::patch('/{business}/branches/{branch}/users/{userId}', [BusinessBranchController::class, 'updateBranchUser']);
        Route::delete('/{business}/branches/{branch}/users/{userId}', [BusinessBranchController::class, 'destroyBranchUser']);

        Route::get('/{business}/branches/{branch}/invitations', [\App\Http\Controllers\Api\Business\BranchInvitationController::class, 'index']);
        Route::post('/{business}/branches/{branch}/invitations', [\App\Http\Controllers\Api\Business\BranchInvitationController::class, 'store']);
        Route::post('/{business}/branches/{branch}/invitations/{invitation}/resend', [\App\Http\Controllers\Api\Business\BranchInvitationController::class, 'resend']);
        Route::post('/{business}/branches/{branch}/invitations/{invitation}/revoke', [\App\Http\Controllers\Api\Business\BranchInvitationController::class, 'revoke']);
    });

    $restaurantPortal = [
        EnsureRole::class.':restaurant_owner,restaurant_manager,restaurant_staff,super_admin',
        EnsureRestaurantAccess::class,
    ];

    Route::get('/restaurant/authorization', [RestaurantAuthorizationController::class, 'show'])
        ->middleware($restaurantPortal);

    Route::prefix('restaurant/profile')->middleware($restaurantPortal)->group(function (): void {
        Route::get('/', [RestaurantProfileController::class, 'show'])
            ->middleware(EnsurePermission::class.':view_restaurant_profile');
        Route::patch('/', [RestaurantProfileController::class, 'update'])
            ->middleware(EnsurePermission::class.':manage_restaurant_profile');
        Route::get('/checklist', [RestaurantProfileController::class, 'checklist'])
            ->middleware(EnsurePermission::class.':view_restaurant_profile');
        Route::post('/activate', [RestaurantProfileController::class, 'activate'])
            ->middleware(EnsurePermission::class.':activate_restaurant');
        Route::post('/temporary-close', [RestaurantProfileController::class, 'temporaryClose'])
            ->middleware(EnsurePermission::class.':temporarily_close_restaurant');
        Route::post('/reopen', [RestaurantProfileController::class, 'reopen'])
            ->middleware(EnsurePermission::class.':temporarily_close_restaurant');
    });

    Route::prefix('restaurant/media')->middleware($restaurantPortal)->middleware(EnsurePermission::class.':manage_restaurant_media')->group(function (): void {
        Route::post('/logo', [RestaurantMediaController::class, 'uploadLogo']);
        Route::post('/cover', [RestaurantMediaController::class, 'uploadCover']);
        Route::post('/gallery', [RestaurantMediaController::class, 'uploadGallery']);
        Route::patch('/{publicId}', [RestaurantMediaController::class, 'updateGallery']);
        Route::delete('/{publicId}', [RestaurantMediaController::class, 'deleteGallery']);
    });

    Route::prefix('restaurant/hours')->middleware($restaurantPortal)->middleware(EnsurePermission::class.':manage_restaurant_hours')->group(function (): void {
        Route::get('/', [RestaurantHoursController::class, 'index']);
        Route::get('/preview', [RestaurantHoursController::class, 'preview']);
        Route::put('/', [RestaurantHoursController::class, 'update']);
    });

    Route::prefix('restaurant/special-hours')->middleware($restaurantPortal)->middleware(EnsurePermission::class.':manage_restaurant_hours')->group(function (): void {
        Route::get('/', [RestaurantHoursController::class, 'listSpecial']);
        Route::post('/', [RestaurantHoursController::class, 'storeSpecial']);
        Route::patch('/{id}', [RestaurantHoursController::class, 'updateSpecial']);
        Route::delete('/{id}', [RestaurantHoursController::class, 'deleteSpecial']);
    });

    Route::prefix('restaurant/service-areas')->middleware($restaurantPortal)->middleware(EnsurePermission::class.':manage_restaurant_service_areas')->group(function (): void {
        Route::get('/', [RestaurantServiceAreaController::class, 'index']);
        Route::post('/', [RestaurantServiceAreaController::class, 'store']);
        Route::patch('/{id}', [RestaurantServiceAreaController::class, 'update']);
        Route::delete('/{id}', [RestaurantServiceAreaController::class, 'destroy']);
    });

    Route::prefix('restaurant/menus')->middleware($restaurantPortal)->middleware(EnsurePermission::class.':view_menu')->group(function (): void {
        Route::get('/', [RestaurantMenuController::class, 'listMenus']);
        Route::post('/', [RestaurantMenuController::class, 'storeMenu'])->middleware(EnsurePermission::class.':manage_menu_categories');
    });

    Route::prefix('restaurant/menu-categories')->middleware($restaurantPortal)->group(function (): void {
        Route::get('/', [RestaurantMenuController::class, 'listCategories'])->middleware(EnsurePermission::class.':view_menu');
        Route::post('/', [RestaurantMenuController::class, 'storeCategory'])->middleware(EnsurePermission::class.':manage_menu_categories');
        Route::patch('/{publicId}', [RestaurantMenuController::class, 'updateCategory'])->middleware(EnsurePermission::class.':manage_menu_categories');
        Route::delete('/{publicId}', [RestaurantMenuController::class, 'deleteCategory'])->middleware(EnsurePermission::class.':manage_menu_categories');
        Route::post('/reorder', [RestaurantMenuController::class, 'reorderCategories'])->middleware(EnsurePermission::class.':manage_menu_categories');
    });

    Route::prefix('restaurant/menu-items')->middleware($restaurantPortal)->group(function (): void {
        Route::get('/', [RestaurantMenuController::class, 'listItems'])->middleware(EnsurePermission::class.':view_menu');
        Route::post('/', [RestaurantMenuController::class, 'storeItem'])->middleware(EnsurePermission::class.':manage_menu_items');
        Route::get('/{publicId}', [RestaurantMenuController::class, 'showItem'])->middleware(EnsurePermission::class.':view_menu');
        Route::patch('/{publicId}', [RestaurantMenuController::class, 'updateItem'])->middleware(EnsurePermission::class.':manage_menu_items');
        Route::delete('/{publicId}', [RestaurantMenuController::class, 'deleteItem'])->middleware(EnsurePermission::class.':manage_menu_items');
        Route::post('/{publicId}/duplicate', [RestaurantMenuController::class, 'duplicateItem'])->middleware(EnsurePermission::class.':manage_menu_items');
        Route::post('/{publicId}/image', [RestaurantMenuController::class, 'uploadItemImage'])->middleware(EnsurePermission::class.':manage_menu_items');
        Route::post('/{publicId}/availability', [RestaurantMenuController::class, 'updateAvailability'])->middleware(EnsurePermission::class.':manage_menu_availability');
        Route::post('/reorder', [RestaurantMenuController::class, 'reorderItems'])->middleware(EnsurePermission::class.':manage_menu_items');
        Route::post('/bulk', [RestaurantMenuController::class, 'bulkItems'])->middleware(EnsurePermission::class.':manage_menu_availability');
        Route::put('/{publicId}/variants', [RestaurantMenuController::class, 'syncVariants'])->middleware(EnsurePermission::class.':manage_menu_variants');
        Route::put('/{publicId}/allergens', [RestaurantMenuController::class, 'syncAllergens'])->middleware(EnsurePermission::class.':manage_menu_allergens');
        Route::put('/{publicId}/modifier-groups', [RestaurantMenuController::class, 'syncItemModifiers'])->middleware(EnsurePermission::class.':manage_menu_modifiers');
    });

    Route::prefix('restaurant/modifier-groups')->middleware($restaurantPortal)->middleware(EnsurePermission::class.':manage_menu_modifiers')->group(function (): void {
        Route::get('/', [RestaurantMenuController::class, 'listModifierGroups']);
        Route::post('/', [RestaurantMenuController::class, 'storeModifierGroup']);
        Route::post('/{groupPublicId}/options', [RestaurantMenuController::class, 'storeModifierOption']);
    });

    Route::prefix('restaurant/inventory')->middleware($restaurantPortal)->group(function (): void {
        Route::get('/', [RestaurantInventoryController::class, 'index'])->middleware(EnsurePermission::class.':view_inventory');
        Route::get('/low-stock', [RestaurantInventoryController::class, 'lowStock'])->middleware(EnsurePermission::class.':view_inventory');
        Route::put('/items/{itemPublicId}', [RestaurantInventoryController::class, 'configure'])->middleware(EnsurePermission::class.':manage_inventory');
        Route::post('/items/{itemPublicId}/adjust', [RestaurantInventoryController::class, 'adjust'])->middleware(EnsurePermission::class.':manage_inventory');
    });

    Route::prefix('restaurant/offers')->middleware($restaurantPortal)->middleware(EnsurePermission::class.':manage_restaurant_offers')->group(function (): void {
        Route::get('/', [RestaurantOfferController::class, 'index']);
        Route::post('/', [RestaurantOfferController::class, 'store']);
        Route::get('/{publicId}', [RestaurantOfferController::class, 'show']);
        Route::patch('/{publicId}', [RestaurantOfferController::class, 'update']);
        Route::delete('/{publicId}', [RestaurantOfferController::class, 'destroy']);
    });

    Route::prefix('restaurant/orders')->middleware($restaurantPortal)->group(function (): void {
        Route::get('/', [RestaurantOrderController::class, 'index'])->middleware(EnsurePermission::class.':view_restaurant_orders');
        Route::get('/{publicId}', [RestaurantOrderController::class, 'show'])->middleware(EnsurePermission::class.':view_restaurant_orders');
        Route::post('/{publicId}/accept', [RestaurantOrderController::class, 'accept'])->middleware(EnsurePermission::class.':accept_restaurant_orders');
        Route::post('/{publicId}/reject', [RestaurantOrderController::class, 'reject'])->middleware(EnsurePermission::class.':reject_restaurant_orders');
        Route::post('/{publicId}/start-preparing', [RestaurantOrderController::class, 'startPreparing'])->middleware(EnsurePermission::class.':prepare_restaurant_orders');
        Route::post('/{publicId}/mark-ready', [RestaurantOrderController::class, 'markReady'])->middleware(EnsurePermission::class.':prepare_restaurant_orders');
        Route::post('/{publicId}/complete-pickup', [RestaurantOrderController::class, 'completePickup'])->middleware(EnsurePermission::class.':complete_restaurant_orders');
        Route::post('/{publicId}/cancel', [RestaurantOrderController::class, 'cancel'])->middleware(EnsurePermission::class.':cancel_restaurant_orders');
        Route::get('/{publicId}/payment-summary', [RestaurantPaymentController::class, 'paymentSummary'])
            ->middleware(EnsurePermission::class.':view_restaurant_payment_summaries');
        Route::post('/{publicId}/refund-requests', [RestaurantPaymentController::class, 'requestRefund'])
            ->middleware(EnsurePermission::class.':request_restaurant_refund');
    });

    Route::prefix('restaurant/payment-account')->middleware($restaurantPortal)->group(function (): void {
        Route::get('/', [RestaurantPaymentAccountController::class, 'show'])
            ->middleware(EnsurePermission::class.':manage_payment_accounts');
        Route::post('/', [RestaurantPaymentAccountController::class, 'store'])
            ->middleware(EnsurePermission::class.':manage_payment_accounts');
        Route::post('/onboarding-link', [RestaurantPaymentAccountController::class, 'onboardingLink'])
            ->middleware(EnsurePermission::class.':manage_payment_accounts');
        Route::post('/refresh', [RestaurantPaymentAccountController::class, 'refresh'])
            ->middleware(EnsurePermission::class.':manage_payment_accounts');
    });

    Route::prefix('restaurant/staff')->middleware($restaurantPortal)->group(function (): void {
        Route::get('/', [RestaurantStaffController::class, 'index'])
            ->middleware(EnsurePermission::class.':manage_restaurant_staff');
        Route::post('/', [RestaurantStaffController::class, 'store'])
            ->middleware(EnsurePermission::class.':manage_restaurant_staff');
        Route::patch('/{userId}', [RestaurantStaffController::class, 'update'])
            ->middleware(EnsurePermission::class.':manage_restaurant_staff');
        Route::delete('/{userId}', [RestaurantStaffController::class, 'destroy'])
            ->middleware(EnsurePermission::class.':manage_restaurant_staff');
    });
});
