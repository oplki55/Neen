(() => {
  const app = document.querySelector("#owner-app");
  if (!(app instanceof HTMLElement)) return;

  const currentUserId = Number(app.dataset.currentUser);
  const panels = [...document.querySelectorAll("[data-panel]")];
  const navItems = [...document.querySelectorAll("[data-section]")];
  const languagePreferenceKey = "neen_control_room_lang";
  const readLanguagePreference = () => {
    try {
      const savedLanguage = window.localStorage.getItem(languagePreferenceKey)
        ?? window.localStorage.getItem("neen_owner_lang");
      return savedLanguage === "ar" ? "ar" : "en";
    } catch (error) {
      console.warn("Owner dashboard language preference could not be read.", error);
      return "en";
    }
  };
  const state = {
    userPage: 1,
    activityPage: 1,
    userQuery: "",
    userStatus: "",
    userSort: "newest",
    activityQuery: "",
    selectedUser: null,
    games: [],
    siteSettings: {},
    newsData: null,
    mediaCategory: "all",
    language: readLanguagePreference(),
  };
  const translations = {
    en: {
      overview: "Daily Operations", users: "Users", wallet: "Wallet & Transactions", modules: "Connected Modules",
      activity: "Admin Activity", ownerVerified: "LIVE · OWNER VERIFIED", ownerDashboard: "NEEN Owner Command Center",
      dailyOperations: "Daily Operations", playersWallet: "Players & Wallet", allSections: "All Sections",
      gamesRewardsNav: "Games & Rewards", siteAudit: "Site Settings & Audit Log",
      newsTickerNav: "News Ticker",
      eventsOrdersAdmin: "Events & Diamond Orders Admin", backToSite: "Back to Live Site",
      diamondQueue: "DIAMOND ORDER QUEUE", reviewDiamondOrders: "Review diamond orders",
      diamondQueueDesc: "Check JOD packages, Free Fire Player IDs, and UWallet / CliQ transfer references before approving or fulfilling.",
      openDiamondQueue: "Open order queue ↗", homepageReward: "HOMEPAGE REWARD",
      rewardQuickConfig: "Configure daily reward",
      rewardQuickConfigDesc: "Update the server-backed claim amount and eligibility window without searching the admin menus.",
      configureReward: "Configure homepage reward ↗", attentionNeeded: "Needs attention",
      noPendingOrders: "No open orders", coinBalancesSplit: "Earned + Purchased Coins",
      activeGamesNote: "Active server-validated games",
      language: "Language", signOut: "Sign out", overviewDesc: "A live snapshot of users, open diamond orders, Coins in circulation, and active games.",
      newAccounts: "New accounts", coinActivity: "Coins activity", rechargeOrders: "Recharge orders", eventVotes: "Event votes",
      recentUsers: "Recent registrations", viewUsers: "View users ↗", recentOrders: "Recent diamond orders",
      openAdmin: "Open admin modules ↗", recentActivity: "Recent admin activity", viewActivity: "View activity ↗",
      usersDesc: "Search, inspect, edit profile details, manage non-owner roles, and suspend accounts. Owner accounts and your own roles are protected.",
      searchUsers: "Search username, email, or ID", status: "Status", sort: "Sort", search: "Search", refresh: "Refresh",
      selectUser: "Select a user to review account and wallet details.", walletDesc: "Wallet changes use the existing transactional ledger, require a reason, prevent negative balances, and record an idempotency key.",
      walletAccount: "Wallet account", ledgerHistory: "Ledger history", selectWalletUser: "Select an account from Users to view its ledger and make a documented adjustment.",
      selectWalletAccount: "Select account", chooseWalletAccount: "Choose an account",
      adjustCoins: "Adjust Coins", direction: "Direction", balanceType: "Balance type", amount: "Amount",
      reason: "Reason (required)", submitAdjustment: "Submit adjustment",
      adjustmentWarning: "Every adjustment is permanent in the ledger. Verify the account, balance type, amount, and reason before submitting.",
      modulesDesc: "Tune daily starts and verified-win rewards for every live game. Open the linked console for other service-backed modules.",
      existingAdmin: "Existing Admin Dashboard", existingAdminDesc: "Games, rewards, attendance, community events, diamond packages and orders, Coins packages and orders.",
      openConsole: "Open console ↗", charts: "Extended reports",
      reportsScope: "The Overview charts are real 30-day database series. Additional reporting dimensions are not yet implemented.",
      notAvailable: "Not available", activityDesc: "Update public site details and review a chronological, read-only record of administrative actions.",
      searchActivity: "Search actor or action", account: "Account", email: "Email", created: "Registered", lastActive: "Last active",
      coins: "Coins", diamonds: "Diamonds", roles: "Roles", actions: "Actions", manage: "Manage",
      page: "Page", of: "of", previous: "Previous", next: "Next", noResults: "No records found.",
      loading: "Loading…", active: "Active", suspended: "Suspended", suspend: "Suspend", activate: "Activate",
      profile: "Profile", saveProfile: "Save profile", fullName: "Full name", displayName: "Display name",
      country: "Country", bio: "Bio", accessRoles: "Access roles", grant: "Grant", revoke: "Revoke",
      selectRole: "Select role", ownerProtected: "Owner accounts are protected from account and role changes.",
      cannotSelf: "You cannot change your own account status or roles.", accountStatus: "Account status", coinBalance: "Total Coins",
      purchased: "Purchased", earned: "Earned", diamondBalance: "Diamonds", transactions: "Recent transactions",
      operation: "Operation", balance: "Balance", source: "Source", date: "Date (UTC)", actor: "Actor",
      entity: "Entity", metadata: "Metadata", ipAddress: "IP address", time: "Time (UTC)", noTransactions: "No Coins ledger entries for this account.",
      noActivity: "No audit entries are available.", noUsers: "No users matched this search.",
      confirmSuspend: "Suspend this account? All active sessions will be revoked.",
      confirmActivate: "Reactivate this account?", confirmRole: "Apply this role change?",
      confirmAdjustment: "Submit this Coins adjustment? This operation will be recorded in the ledger and audit log.",
      saved: "Saved successfully.", failed: "The operation failed.", requestError: "The server returned an error.",
      invalidResponse: "The server returned an unreadable response.", csrfMissing: "Could not obtain a CSRF token. Sign in again.",
      recentRegistrations: "Recent registrations", recentDiamondOrders: "Recent diamond orders",
      playerId: "Player ID", orderNumber: "Order #", paymentStatus: "Payment status",
      grossVolume: "Gross ledger volume", eventsActive: "Active events", votesTotal: "Votes", claims: "Reward claims",
      rewards: "Event rewards", checkIns: "Attendance check-ins", gamesActive: "Active games", walletTransactions: "Wallet transactions",
      gamesRewards: "Games & Rewards", gameSettingsNote: "The daily free-attempt limit and verified-win Coins reward are connected to the game runtime. Board size, paid attempts, and other hard-coded rules are not editable here.",
      gameName: "Game name", gameDescription: "Description", dailyFreeAttempts: "Free attempts per day",
      winReward: "Coins per verified win", gameState: "Publication status", saveGame: "Save game settings",
      confirmGame: "Save these game settings? Changes take effect in the game runtime.",
      siteSettings: "Site Settings", tiktokProfile: "TikTok profile URL", siteDescription: "Site description",
      saveSettings: "Save site settings", contentManagement: "Content Management",
      mediaBadgeCustom: "Custom image", mediaBadgeOriginal: "Original image",
      mediaChangeBtn: "Replace image", mediaResetBtn: "Reset image",
      confirmResetMedia: "Restore this image to its original artwork?",
      confirmDeleteNews: "Delete this news ticker message?",
      mediaUploadingBtn: "Uploading…", mediaCardDesc: "Replace the images and artwork used across the live site.",
      openMediaManager: "Open media manager ↗", mediaManagerEyebrow: "VISUAL ASSETS & MEDIA",
      siteMediaTitle: "Site Images & Media Manager", mediaCatAll: "All Sections (43)",
      mediaCatHero: "Hero & Pages", mediaCatGames: "Games Artwork",
      mediaCatVideos: "Video Posters", mediaCatGallery: "Character Gallery (18)",
      siteMediaDesc: "Upload a WebP, PNG, or JPG image up to 5 MB to replace live artwork, or reset it to the original.",
      mediaTooLarge: "Image must be 5 MB or less.",
      notConnected: "Videos, gallery assets, and page copy remain static files. No CMS editing controls are exposed.",
      requiresIntegration: "Requires a media workflow and public-page integration",
      totalUsers: "Total users", newUsers: "New accounts (30d)", activeUsers: "Active users (30d)",
      totalCoins: "Coins in wallets", totalOrders: "Diamond orders", pendingOrders: "Open diamond orders",
      cancel: "Cancel", networkError: "Request could not be completed. Check your connection and try again.",
      documentTitle: "Owner Dashboard — NEEN", ownerControlRoom: "OWNER CONTROL ROOM",
      ownerOperations: "NEEN / OWNER COMMAND CENTER", ownerRole: "Owner", ownerNavLabel: "Owner navigation",
      homeLabel: "NEEN Home", sectionsLabel: "Dashboard sections", switchLanguage: "Switch dashboard language",
      liveDatabase: "LIVE DATABASE", thirtyDays: "30 DAYS", latest: "LATEST",
      accountManagement: "ACCOUNT MANAGEMENT", immutableLedger: "IMMUTABLE COINS LEDGER",
      selectedAccount: "SELECTED ACCOUNT", recentEntries: "RECENT ENTRIES",
      auditedOperation: "AUDITED OPERATION", existingServices: "EXISTING SERVICES",
      runtimeSettings: "RUNTIME-BACKED SETTINGS", publicConfiguration: "PUBLIC SITE CONFIGURATION",
      readOnlyAudit: "READ-ONLY AUDIT", loadingStats: "Loading live statistics…",
      loadingUsers: "Loading user directory…", loadingOrders: "Loading orders…",
      loadingActivity: "Loading audit records…", loadingGameSettings: "Loading game settings…",
      searchPlaceholder: "Search...", reasonPlaceholder: "Explain this adjustment",
      allStatuses: "All statuses", deleted: "Deleted", pendingVerification: "Pending verification",
      pending_verification: "Pending verification",
      newest: "Newest", username: "Username", credit: "Add", debit: "Deduct",
      pending: "Pending", pending_review: "Pending review", awaiting_payment: "Awaiting payment",
      payment_verification: "Payment verification", paid: "Paid", not_started: "Not started",
      processing: "Processing", delivered: "Delivered", cancelled: "Cancelled",
      refunded: "Refunded", verification: "Verification",
      rejected: "Rejected", approved: "Approved", completed: "Completed", disabled: "Disabled",
      failedStatus: "Failed", maintenance: "Maintenance", verified: "Verified",
      notVerified: "Not verified", system: "System",
      paymentReference: "Payment reference",
      role_user: "User", role_moderator: "Moderator", role_game_admin: "Game administrator",
      role_support_admin: "Support administrator", role_super_admin: "Super administrator",
      role_owner: "Owner", action_owner_user_status_changed: "Owner changed user status",
      action_owner_user_role_changed: "Owner changed user role",
      action_owner_user_profile_changed: "Owner updated user profile",
      action_owner_game_settings_changed: "Owner changed game settings",
      action_owner_site_settings_changed: "Owner changed site settings",
      entity_users: "Users", entity_coin_ledger: "Coins ledger",
      entity_game_catalog: "Game catalog", entity_site_settings: "Site settings",
      operation_credit: "Credit", operation_debit: "Debit",
      source_earned_reward: "Earned reward", source_game_reward: "Game reward",
      game_memory_match: "Memory Match", game_picture_jigsaw: "Picture Jigsaw",
      game_vault_cracker: "NEEN Target Rush", game_loot_sequence: "Loot Sequence Strike",
      game_sunny_island: "NEEN Sunny Island",
      action_community_event_created: "Created a community event",
      action_community_event_results_approved: "Approved community event results",
      action_community_event_reward_issued: "Issued a community event reward",
      action_community_event_updated: "Updated a community event",
      action_community_event_suggestion_accepted: "Accepted an event suggestion",
      action_community_event_suggestion_approved: "Approved an event suggestion",
      action_community_event_suggestion_rejected: "Rejected an event suggestion",
      action_diamond_package_created: "Created a diamond package",
      action_diamond_package_updated: "Updated a diamond package",
      action_diamond_order_begin_verification: "Started payment verification",
      action_diamond_order_mark_paid: "Marked diamond order paid",
      action_diamond_order_reject_payment: "Rejected diamond payment",
      action_diamond_order_fail_payment: "Marked diamond payment failed",
      action_diamond_order_begin_delivery: "Started diamond delivery",
      action_diamond_order_mark_delivered: "Marked diamond order delivered",
      action_diamond_order_fail_delivery: "Marked diamond delivery failed",
      action_diamond_order_refund: "Refunded a diamond order",
      action_homepage_reward_configured: "Configured homepage rewards",
      action_user_registered: "Registered an account",
      action_email_verified: "Verified an email address",
      action_user_login: "Signed in",
      action_user_logout: "Signed out",
      action_password_reset_completed: "Reset an account password",
      action_user_profile_updated: "Updated a user profile",
      action_coin_order_created: "Created a Coins order",
      action_coin_order_evidence_submitted: "Submitted Coins payment evidence",
      action_coin_package_created: "Created a Coins package",
      action_coin_package_updated: "Updated a Coins package",
      action_coin_payment_method_created: "Created a Coins payment method",
      action_coin_payment_method_updated: "Updated a Coins payment method",
      action_coin_order_approved: "Approved a Coins order",
      action_coin_order_rejected: "Rejected a Coins order",
      action_wallet_adjusted: "Adjusted a wallet",
      action_coin_ledger_reversed: "Reversed a Coins ledger entry",
      diamondOrders: "Diamond recharge orders", diamondAmount: "Diamonds",
      deliveryStatus: "Delivery status",
      price: "Price", noDiamondOrders: "No diamond recharge orders for this account.",
      entity_community_events: "Community events", entity_community_event_rewards: "Community event rewards",
      entity_community_event_suggestions: "Event suggestions", entity_diamond_packages: "Diamond packages",
      entity_diamond_recharge_orders: "Diamond recharge orders", entity_homepage_reward_config: "Homepage reward settings",
      entity_user_sessions: "User sessions", entity_user_profiles: "User profiles",
      entity_coin_orders: "Coins orders", entity_coin_packages: "Coins packages",
      entity_coin_payment_methods: "Coins payment methods",
    },
    ar: {
      overview: "العمليات اليومية", users: "المستخدمون", wallet: "المحفظة والمعاملات", modules: "الأنظمة المتصلة",
      activity: "سجل نشاط الإدارة", ownerVerified: "مباشر · تم التحقق من المالك", ownerDashboard: "مركز قيادة مالك NEEN",
      dailyOperations: "العمليات اليومية", playersWallet: "اللاعبون والمحفظة", allSections: "كل الأقسام",
      gamesRewardsNav: "الألعاب والمكافآت", siteAudit: "إعدادات الموقع وسجل النشاط", newsTickerNav: "إدارة شريط الأخبار",
      eventsOrdersAdmin: "إدارة الفعاليات وطلبات الجواهر", backToSite: "العودة إلى الموقع",
      diamondQueue: "قائمة طلبات الجواهر", reviewDiamondOrders: "مراجعة طلبات الجواهر",
      diamondQueueDesc: "تحقق من باقات JOD ومعرفات لاعبي Free Fire ومراجع تحويل UWallet أو CliQ قبل الاعتماد أو التسليم.",
      openDiamondQueue: "فتح قائمة الطلبات ↗", homepageReward: "مكافأة الصفحة الرئيسية",
      rewardQuickConfig: "إعداد المكافأة اليومية",
      rewardQuickConfigDesc: "حدّث قيمة المكافأة ومدة الاستحقاق المرتبطتين بالخادم مباشرة.",
      configureReward: "إعداد مكافأة الصفحة الرئيسية ↗", attentionNeeded: "تتطلب الانتباه",
      noPendingOrders: "لا توجد طلبات مفتوحة", coinBalancesSplit: "Coins المكتسبة والمشتراة",
      activeGamesNote: "ألعاب نشطة يتحقق منها الخادم",
      language: "اللغة", signOut: "تسجيل الخروج", overviewDesc: "ملخص مباشر للمستخدمين وطلبات الجواهر المفتوحة ورصيد Coins والألعاب النشطة.",
      newAccounts: "الحسابات الجديدة", coinActivity: "نشاط العملات", rechargeOrders: "طلبات الشحن", eventVotes: "أصوات الفعاليات",
      recentUsers: "أحدث التسجيلات", viewUsers: "عرض المستخدمين ↗", recentOrders: "أحدث طلبات الجواهر",
      openAdmin: "فتح أقسام الإدارة ↗", recentActivity: "أحدث نشاط إداري", viewActivity: "عرض السجل ↗",
      usersDesc: "ابحث عن الحسابات وراجعها وعدّل بيانات الملف الشخصي وأدر الأدوار غير المخصصة للمالك وأوقف الحسابات. حساب المالك وأدوارك محمية.",
      searchUsers: "بحث باسم المستخدم أو البريد أو الرقم", status: "الحالة", sort: "الترتيب", search: "بحث", refresh: "تحديث",
      selectUser: "اختر مستخدمًا لمراجعة الحساب والمحفظة.", walletDesc: "تُنفذ تغييرات المحفظة عبر سجل المعاملات الحالي مع سبب إلزامي ومنع الرصيد السالب ومفتاح منع التكرار.",
      walletAccount: "محفظة المستخدم", ledgerHistory: "سجل المعاملات", selectWalletUser: "اختر حسابًا من المستخدمين لعرض سجله وإجراء تعديل موثق.",
      selectWalletAccount: "اختر الحساب", chooseWalletAccount: "اختر حسابًا",
      adjustCoins: "تعديل العملات", direction: "العملية", balanceType: "نوع الرصيد", amount: "الكمية",
      reason: "السبب (إلزامي)", submitAdjustment: "تنفيذ التعديل",
      adjustmentWarning: "يُسجل كل تعديل نهائيًا. تحقق من الحساب ونوع الرصيد والكمية والسبب قبل التنفيذ.",
      modulesDesc: "اضبط المحاولات اليومية ومكافآت الفوز المتحقق لكل الألعاب. افتح لوحة الإدارة للأنظمة الأخرى المرتبطة بالخدمات.",
      existingAdmin: "لوحة الإدارة الحالية", existingAdminDesc: "الألعاب والمكافآت والحضور والفعاليات وباقات الجواهر وطلباتها وباقات Coins وطلباتها.",
      openConsole: "فتح لوحة الإدارة ↗", charts: "تقارير إضافية",
      reportsScope: "المخططات في النظرة العامة مبنية على بيانات حقيقية لآخر 30 يومًا. لم تنفذ أبعاد تقارير أخرى بعد.",
      notAvailable: "غير متاح", activityDesc: "حدّث بيانات الموقع العامة وراجع سجلًا زمنيًا للقراءة فقط للعمليات الإدارية.",
      searchActivity: "بحث بالمنفذ أو العملية", account: "الحساب", email: "البريد", created: "تاريخ التسجيل", lastActive: "آخر نشاط",
      coins: "Coins", diamonds: "جواهر", roles: "الأدوار", actions: "إجراءات", manage: "إدارة",
      page: "صفحة", of: "من", previous: "السابق", next: "التالي", noResults: "لا توجد سجلات.",
      loading: "جارٍ التحميل…", active: "نشط", suspended: "موقوف", suspend: "إيقاف", activate: "تفعيل",
      profile: "الملف الشخصي", saveProfile: "حفظ الملف", fullName: "الاسم الكامل", displayName: "اسم العرض",
      country: "الدولة", bio: "نبذة", accessRoles: "صلاحيات الوصول", grant: "منح", revoke: "إلغاء",
      selectRole: "اختر دورًا", ownerProtected: "حسابات المالك محمية من تعديل الحساب والأدوار.",
      cannotSelf: "لا يمكنك تغيير حالة حسابك أو أدوارك.", accountStatus: "حالة الحساب", coinBalance: "إجمالي Coins",
      purchased: "مشتراة", earned: "مكتسبة", diamondBalance: "الجواهر", transactions: "أحدث المعاملات",
      operation: "العملية", balance: "الرصيد", source: "المصدر", date: "التاريخ (UTC)", actor: "المنفذ",
      entity: "الكيان", metadata: "البيانات", ipAddress: "عنوان IP", time: "التوقيت (UTC)", noTransactions: "لا توجد معاملات Coins لهذا الحساب.",
      noActivity: "لا توجد سجلات نشاط.", noUsers: "لا يوجد مستخدمون يطابقون البحث.",
      confirmSuspend: "إيقاف هذا الحساب؟ سيتم إبطال جميع الجلسات النشطة.",
      confirmActivate: "إعادة تفعيل هذا الحساب؟", confirmRole: "تأكيد تغيير الدور؟",
      confirmAdjustment: "تنفيذ تعديل Coins؟ سيُسجل في دفتر المحفظة وسجل الإدارة.",
      saved: "تم الحفظ بنجاح.", failed: "تعذر تنفيذ العملية.", requestError: "أعاد الخادم خطأ.",
      invalidResponse: "تعذر قراءة استجابة الخادم.", csrfMissing: "تعذر الحصول على رمز الحماية. سجّل الدخول مجددًا.",
      recentRegistrations: "أحدث التسجيلات", recentDiamondOrders: "أحدث طلبات الجواهر",
      playerId: "معرف اللاعب", orderNumber: "رقم الطلب", paymentStatus: "حالة الدفع",
      grossVolume: "إجمالي حجم المعاملات", eventsActive: "الفعاليات النشطة", votesTotal: "الأصوات", claims: "مطالبات المكافآت",
      rewards: "مكافآت الفعاليات", checkIns: "تسجيلات الحضور", gamesActive: "الألعاب النشطة", walletTransactions: "معاملات المحفظة",
      gamesRewards: "الألعاب والمكافآت", gameSettingsNote: "حد المحاولات المجانية اليومي ومكافأة الفوز التي يتحقق منها الخادم مرتبطان بمحرك اللعبة. حجم اللوحة والمحاولات المدفوعة والقواعد الثابتة غير قابلة للتعديل هنا.",
      gameName: "اسم اللعبة", gameDescription: "الوصف", dailyFreeAttempts: "المحاولات المجانية يوميًا",
      winReward: "Coins للفوز المتحقق", gameState: "حالة النشر", saveGame: "حفظ إعدادات اللعبة",
      confirmGame: "حفظ إعدادات اللعبة؟ ستطبق التغييرات مباشرة في نظام اللعبة.",
      siteSettings: "إعدادات الموقع", tiktokProfile: "رابط حساب TikTok", siteDescription: "وصف الموقع",
      saveSettings: "حفظ إعدادات الموقع", contentManagement: "إدارة المحتوى",
      mediaBadgeCustom: "صورة مخصصة", mediaBadgeOriginal: "الصورة الأصلية",
      mediaChangeBtn: "استبدال الصورة", mediaResetBtn: "إعادة الصورة الأصلية",
      confirmResetMedia: "هل تريد استعادة الصورة الأصلية؟",
      confirmDeleteNews: "هل تريد حذف رسالة شريط الأخبار؟",
      mediaUploadingBtn: "جارٍ الرفع…", mediaCardDesc: "استبدل الصور والرسومات المستخدمة في الموقع مباشرة.",
      openMediaManager: "فتح مدير الوسائط ↗", mediaManagerEyebrow: "الصور والوسائط",
      siteMediaTitle: "مدير صور ووسائط الموقع", mediaCatAll: "كل الأقسام (43)",
      mediaCatHero: "الواجهة والصفحات", mediaCatGames: "صور الألعاب",
      mediaCatVideos: "أغلفة الفيديو", mediaCatGallery: "معرض الشخصيات (18)",
      siteMediaDesc: "ارفع صورة WebP أو PNG أو JPG بحجم أقصى 5 MB لاستبدال صورة بالموقع، أو استعد الصورة الأصلية.",
      mediaTooLarge: "يجب ألا يتجاوز حجم الصورة 5 MB.",
      notConnected: "الفيديوهات وملفات المعرض ونصوص الصفحات ملفات ثابتة. لا توجد أدوات تعديل CMS شكلية.",
      requiresIntegration: "يتطلب نظامًا للوسائط وربط الصفحات العامة",
      totalUsers: "إجمالي المستخدمين", newUsers: "حسابات جديدة (30 يومًا)", activeUsers: "مستخدمون نشطون (30 يومًا)",
      totalCoins: "Coins في المحافظ", totalOrders: "طلبات الجواهر", pendingOrders: "طلبات جواهر مفتوحة",
      cancel: "إلغاء", networkError: "تعذر إكمال الطلب. تحقق من الاتصال وحاول مجددًا.",
      documentTitle: "لوحة المالك — NEEN", ownerControlRoom: "غرفة تحكم المالك",
      ownerOperations: "NEEN / مركز قيادة المالك", ownerRole: "المالك", ownerNavLabel: "تنقل المالك",
      homeLabel: "الرئيسية — NEEN", sectionsLabel: "أقسام لوحة التحكم", switchLanguage: "تغيير لغة لوحة التحكم",
      liveDatabase: "قاعدة البيانات المباشرة", thirtyDays: "آخر 30 يومًا", latest: "الأحدث",
      accountManagement: "إدارة الحسابات", immutableLedger: "سجل Coins غير القابل للتغيير",
      selectedAccount: "الحساب المحدد", recentEntries: "أحدث القيود",
      auditedOperation: "عملية موثقة", existingServices: "الخدمات الحالية",
      runtimeSettings: "إعدادات مرتبطة بمحرك التشغيل", publicConfiguration: "إعدادات الموقع العامة",
      readOnlyAudit: "سجل مراجعة للقراءة فقط", loadingStats: "جارٍ تحميل الإحصاءات المباشرة…",
      loadingUsers: "جارٍ تحميل دليل المستخدمين…", loadingOrders: "جارٍ تحميل الطلبات…",
      loadingActivity: "جارٍ تحميل سجلات المراجعة…", loadingGameSettings: "جارٍ تحميل إعدادات الألعاب…",
      searchPlaceholder: "بحث…", reasonPlaceholder: "اشرح سبب هذا التعديل",
      allStatuses: "كل الحالات", deleted: "محذوف", pendingVerification: "بانتظار التحقق",
      pending_verification: "بانتظار التحقق",
      newest: "الأحدث", username: "اسم المستخدم", credit: "إضافة", debit: "خصم",
      pending: "قيد الانتظار", pending_review: "بانتظار المراجعة", awaiting_payment: "بانتظار الدفع",
      payment_verification: "التحقق من الدفع", paid: "مدفوع", not_started: "لم يبدأ",
      processing: "قيد التنفيذ", delivered: "تم التسليم", cancelled: "ملغي",
      refunded: "تم رد المبلغ", verification: "التحقق",
      rejected: "مرفوض", approved: "مقبول", completed: "مكتمل", disabled: "معطل",
      failedStatus: "فشل", maintenance: "صيانة", verified: "تم التحقق",
      notVerified: "لم يتم التحقق", system: "النظام",
      paymentReference: "مرجع الدفع",
      role_user: "مستخدم", role_moderator: "مشرف", role_game_admin: "مدير الألعاب",
      role_support_admin: "مدير الدعم", role_super_admin: "مدير عام",
      role_owner: "المالك", action_owner_user_status_changed: "غيّر المالك حالة المستخدم",
      action_owner_user_role_changed: "غيّر المالك دور المستخدم",
      action_owner_user_profile_changed: "عدّل المالك الملف الشخصي للمستخدم",
      action_owner_game_settings_changed: "غيّر المالك إعدادات الألعاب",
      action_owner_site_settings_changed: "غيّر المالك إعدادات الموقع",
      entity_users: "المستخدمون", entity_coin_ledger: "سجل Coins",
      entity_game_catalog: "كتالوج الألعاب", entity_site_settings: "إعدادات الموقع",
      operation_credit: "إضافة رصيد", operation_debit: "خصم رصيد",
      source_earned_reward: "مكافأة مكتسبة", source_game_reward: "مكافأة لعبة",
      game_memory_match: "مطابقة الذاكرة", game_picture_jigsaw: "أحجية الصور",
      game_vault_cracker: "تحدي الأهداف السريع من نين", game_loot_sequence: "تسلسل الغنائم",
      game_sunny_island: "جزيرة نين المشمسة",
      action_community_event_created: "أنشأ فعالية مجتمعية",
      action_community_event_results_approved: "اعتمد نتائج فعالية مجتمعية",
      action_community_event_reward_issued: "أصدر مكافأة فعالية مجتمعية",
      action_community_event_updated: "عدّل فعالية مجتمعية",
      action_community_event_suggestion_accepted: "قبل اقتراح فعالية",
      action_community_event_suggestion_approved: "اعتمد اقتراح فعالية",
      action_community_event_suggestion_rejected: "رفض اقتراح فعالية",
      action_diamond_package_created: "أنشأ باقة جواهر",
      action_diamond_package_updated: "عدّل باقة جواهر",
      action_diamond_order_begin_verification: "بدأ التحقق من الدفع",
      action_diamond_order_mark_paid: "أكد دفع طلب الجواهر",
      action_diamond_order_reject_payment: "رفض دفع الجواهر",
      action_diamond_order_fail_payment: "سجّل فشل دفع الجواهر",
      action_diamond_order_begin_delivery: "بدأ تسليم الجواهر",
      action_diamond_order_mark_delivered: "أكد تسليم طلب الجواهر",
      action_diamond_order_fail_delivery: "سجّل فشل تسليم الجواهر",
      action_diamond_order_refund: "رد مبلغ طلب الجواهر",
      action_homepage_reward_configured: "ضبط مكافآت الصفحة الرئيسية",
      action_user_registered: "سجّل حسابًا",
      action_email_verified: "تحقق من عنوان بريد إلكتروني",
      action_user_login: "سجّل الدخول",
      action_user_logout: "سجّل الخروج",
      action_password_reset_completed: "أعاد تعيين كلمة مرور حساب",
      action_user_profile_updated: "عدّل ملف مستخدم",
      action_coin_order_created: "أنشأ طلب Coins",
      action_coin_order_evidence_submitted: "أرسل إثبات دفع Coins",
      action_coin_package_created: "أنشأ باقة Coins",
      action_coin_package_updated: "عدّل باقة Coins",
      action_coin_payment_method_created: "أنشأ وسيلة دفع Coins",
      action_coin_payment_method_updated: "عدّل وسيلة دفع Coins",
      action_coin_order_approved: "اعتمد طلب Coins",
      action_coin_order_rejected: "رفض طلب Coins",
      action_wallet_adjusted: "عدّل رصيد المحفظة",
      action_coin_ledger_reversed: "عكس قيدًا في سجل Coins",
      diamondOrders: "طلبات شحن الجواهر", diamondAmount: "كمية الجواهر",
      deliveryStatus: "حالة التسليم",
      price: "السعر", noDiamondOrders: "لا توجد طلبات شحن جواهر لهذا الحساب.",
      entity_community_events: "الفعاليات المجتمعية", entity_community_event_rewards: "مكافآت الفعاليات",
      entity_community_event_suggestions: "اقتراحات الفعاليات", entity_diamond_packages: "باقات الجواهر",
      entity_diamond_recharge_orders: "طلبات شحن الجواهر", entity_homepage_reward_config: "إعدادات مكافآت الصفحة الرئيسية",
      entity_user_sessions: "جلسات المستخدمين", entity_user_profiles: "ملفات المستخدمين",
      entity_coin_orders: "طلبات Coins", entity_coin_packages: "باقات Coins",
      entity_coin_payment_methods: "وسائل دفع Coins",
    },
  };

  const t = (key) => translations[state.language][key] ?? translations.en[key] ?? key;
  const translateCode = (value, namespace = "") => {
    if (typeof value !== "string" || value === "") return "—";
    const normalized = value.trim().toLowerCase().replace(/[\s-]+/g, "_");
    const key = namespace
      ? `${namespace}_${normalized}`
      : normalized === "failed"
        ? "failedStatus"
        : normalized;
    return translations[state.language][key] ?? translations.en[key] ?? value;
  };
  const localizeGameName = (game) => translateCode(game.slug, "game") || game.name;
  const markLtr = (element) => {
    element.dir = "ltr";
    element.classList.add("owner-ltr");
    return element;
  };
  const formatNumber = (value) => Number(value ?? 0).toLocaleString(state.language);
  const make = (tag, className = "", text = undefined) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = String(text);
    return element;
  };
  const ltrText = (value) => markLtr(make("bdi", "", value ?? "—"));
  const accountMeta = (id, status, date) => {
    const meta = make("small");
    meta.append(`${t("account")} `, ltrText(`#${id}`), ` · ${translateCode(status)} · `, ltrText(formatDate(date)));
    return meta;
  };
  const showState = (container, message, error = false) => {
    container.replaceChildren(make("p", `admin-state${error ? " is-error" : ""}`, message));
  };
  const formatDate = (value) => typeof value === "string" && value !== "" ? value.replace("T", " ").replace(/\.\d+Z$/, " UTC") : "—";
  const toast = (message, error = false) => {
    const element = document.querySelector("[data-owner-toast]");
    if (!(element instanceof HTMLElement)) return;
    element.textContent = message;
    element.classList.toggle("is-error", error);
    element.classList.add("is-visible");
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => element.classList.remove("is-visible"), 4000);
  };

  const apiRequest = async (path, options = {}) => {
    let response;
    try {
      response = await fetch(path, {
        ...options,
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          ...(options.body ? { "Content-Type": "application/json" } : {}),
          ...(options.headers ?? {}),
        },
      });
    } catch {
      throw new Error(t("networkError"));
    }
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error(t("invalidResponse"));
    }
    if (!response.ok || result.success !== true) {
      const message = result.errors?.[0]?.message;
      throw new Error(
        state.language === "en" && typeof message === "string"
          ? message
          : `${t("requestError")} (${response.status})`,
      );
    }
    return result.data;
  };

  const mutate = async (path, method, payload) => {
    const csrf = await apiRequest("/api/auth/csrf");
    if (typeof csrf.csrf_token !== "string") throw new Error(t("csrfMissing"));
    return apiRequest(path, {
      method,
      headers: { "X-CSRF-Token": csrf.csrf_token },
      body: JSON.stringify(payload),
    });
  };

  const withButtonLock = async (button, action) => {
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    button.disabled = true;
    try {
      return await action();
    } finally {
      button.disabled = false;
    }
  };

  const lockFormSubmit = (form) => {
    if (form.dataset.mutating === "true") return null;
    form.dataset.mutating = "true";
    const button = form.querySelector('button[type="submit"]');
    const wasDisabled = button instanceof HTMLButtonElement && button.disabled;
    if (button instanceof HTMLButtonElement) button.disabled = true;
    return () => {
      delete form.dataset.mutating;
      if (button instanceof HTMLButtonElement) button.disabled = wasDisabled;
    };
  };

  const buildTable = (headers, rows) => {
    const wrapper = make("div", "admin-table-wrap");
    const table = make("table", "admin-table");
    const head = make("thead");
    const headRow = make("tr");
    headers.forEach((label) => headRow.append(make("th", "", label)));
    head.append(headRow);
    const body = make("tbody");
    rows.forEach((row) => body.append(row));
    table.append(head, body);
    wrapper.append(table);
    return wrapper;
  };

  const addPagination = (container, pagination, onPage) => {
    container.replaceChildren();
    if (!pagination || pagination.pages <= 1) return;
    const previous = make("button", "", t("previous"));
    previous.type = "button";
    previous.disabled = pagination.page <= 1;
    previous.addEventListener("click", () => onPage(pagination.page - 1));
    const label = make("span");
    label.append(
      `${t("page")} `,
      ltrText(pagination.page),
      ` ${t("of")} `,
      ltrText(pagination.pages),
      " · ",
      ltrText(pagination.total),
    );
    const next = make("button", "", t("next"));
    next.type = "button";
    next.disabled = pagination.page >= pagination.pages;
    next.addEventListener("click", () => onPage(pagination.page + 1));
    container.append(previous, label, next);
  };

  const openSection = (section) => {
    const showAll = section === "wallet";
    const activePanels = showAll
      ? panels.map((panel) => panel.dataset.panel)
      : section === "users"
        ? ["users", "wallet"]
        : [section];
    navItems.forEach((item) => {
      const active = item.dataset.section === section;
      item.classList.toggle("is-active", active);
      if (active) item.setAttribute("aria-current", "page");
      else item.removeAttribute("aria-current");
    });
    panels.forEach((panel) => {
      const active = activePanels.includes(panel.dataset.panel);
      panel.hidden = !active;
      panel.classList.toggle("is-active", active);
    });
    if (activePanels.includes("overview")) loadOverview();
    if (activePanels.includes("users")) loadUsers();
    if (activePanels.includes("activity")) loadSiteSettings();
    if (activePanels.includes("modules")) loadGames();
    if (activePanels.includes("news")) loadNewsTicker();
    if (activePanels.includes("activity")) loadActivity();
    if (activePanels.includes("wallet")) loadWalletChoices();
  };

  const renderChart = (container, rows) => {
    if (!Array.isArray(rows) || rows.length === 0) {
      showState(container, t("noResults"));
      return;
    }
    const values = rows.map((row) => Number(row.value) || 0);
    const max = Math.max(...values, 1);
    container.replaceChildren();
    rows.slice(-30).forEach((row) => {
      const column = make("span", "owner-chart-bar");
      column.dir = "ltr";
      const value = Number(row.value) || 0;
      column.title = `${row.day}: ${value}`;
      const bar = make("i");
      bar.style.height = `${Math.max(2, value / max * 100)}%`;
      bar.setAttribute("aria-label", `${row.day}: ${value}`);
      const amount = markLtr(make("b", "owner-chart-tooltip", formatNumber(value)));
      const day = markLtr(make("small", "", String(row.day).slice(5)));
      column.append(bar, amount, day);
      container.append(column);
    });
  };

  const renderCompactRows = (container, rows, type) => {
    if (!Array.isArray(rows) || rows.length === 0) {
      showState(container, t("noResults"));
      return;
    }
    const list = make("div", "owner-compact-list");
    rows.forEach((item) => {
      const row = make("div", "owner-compact-item");
      const main = make("span");
      if (type === "users") {
        main.append(markLtr(make("strong", "", item.username)));
        main.append(accountMeta(item.id, item.status, item.created_at));
        const action = make("button", "owner-table-action", t("manage"));
        action.type = "button";
        action.addEventListener("click", () => selectUser(item.id, "users"));
        row.append(main, action);
      } else if (type === "orders") {
        const isPending = ["pending", "payment_verification", "paid", "processing"].includes(item.order_status);
        row.classList.toggle("owner-compact-order", true);
        row.classList.toggle("is-attention", isPending);
        main.append(markLtr(make("strong", "", `${item.order_number} · ${item.username}`)));
        const orderMeta = make("small");
        orderMeta.append(
          `${t("playerId")} `,
          ltrText(item.player_id),
          " · ",
          ltrText(formatNumber(item.diamond_amount)),
          " · ",
          ltrText(`${item.price} ${item.currency_code}`),
          " · ",
          ltrText(formatDate(item.created_at)),
        );
        main.append(orderMeta);
        if (item.payment_reference) {
          const reference = make("small");
          reference.append(`${t("paymentReference")}: `, ltrText(item.payment_reference));
          main.append(reference);
        }
        const status = make("span", `admin-status-pill${isPending ? " is-attention" : ""}`, translateCode(item.order_status));
        row.append(main, status);
      } else {
        const title = make("strong");
        title.append(
          `${translateCode(item.action_type, "action")} · `,
          ltrText(item.actor_username ?? t("system")),
        );
        main.append(title);
        const activityMeta = make("small");
        activityMeta.append(
          `${translateCode(item.entity_type, "entity")} `,
          ltrText(`#${item.entity_id ?? "—"}`),
          ` · `,
          ltrText(formatDate(item.created_at)),
        );
        main.append(activityMeta);
        row.append(main);
      }
      list.append(row);
    });
    container.replaceChildren(list);
  };

  const loadOverview = async () => {
    const stats = document.querySelector("[data-owner-stats]");
    if (!(stats instanceof HTMLElement)) return;
    showState(stats, t("loading"));
    try {
      const { overview } = await apiRequest("/api/owner/dashboard");
      const count = overview.counts;
      const pendingOrders = Number(count.diamond_orders_pending) || 0;
      const values = [
        ["totalUsers", count.users_total, ""],
        ["pendingOrders", pendingOrders, pendingOrders > 0 ? t("attentionNeeded") : t("noPendingOrders")],
        ["totalCoins", count.coins_circulating, t("coinBalancesSplit")],
        ["gamesActive", `${formatNumber(count.games_active)} / ${formatNumber(count.games_total)}`, t("activeGamesNote")],
      ];
      const cards = values.map(([label, value, note]) => {
        const card = make("article", "owner-stat-card");
        const formattedValue = typeof value === "number" ? formatNumber(value) : value;
        if (label === "pendingOrders" && pendingOrders > 0) card.classList.add("has-attention");
        card.append(make("span", "", t(label)), markLtr(make("strong", "", formattedValue)));
        if (note) card.append(make("small", "", note));
        return card;
      });
      stats.replaceChildren(...cards);
      const orderQueue = document.querySelector("[data-owner-order-queue]");
      const pendingCount = document.querySelector("[data-owner-pending-count]");
      if (orderQueue instanceof HTMLElement) orderQueue.classList.toggle("has-pending", pendingOrders > 0);
      if (pendingCount instanceof HTMLElement) pendingCount.textContent = formatNumber(pendingOrders);
      for (const key of ["users", "wallet_transactions", "diamond_orders", "event_votes"]) {
        const chart = document.querySelector(`[data-chart="${key}"]`);
        if (chart instanceof HTMLElement) renderChart(chart, overview.series[key]);
      }
      renderCompactRows(document.querySelector("[data-recent-users]"), overview.recent_users, "users");
      renderCompactRows(document.querySelector("[data-recent-orders]"), overview.recent_diamond_orders, "orders");
      renderCompactRows(document.querySelector("[data-recent-activity]"), overview.recent_activity, "activity");
    } catch (error) {
      showState(stats, error instanceof Error ? error.message : t("failed"), true);
    }
  };

  const loadUsers = async (page = state.userPage) => {
    const container = document.querySelector("[data-owner-users]");
    const pagination = document.querySelector("[data-owner-pagination]");
    if (!(container instanceof HTMLElement) || !(pagination instanceof HTMLElement)) return;
    state.userPage = page;
    showState(container, t("loading"));
    try {
      const query = new URLSearchParams({ page: String(page), sort: state.userSort });
      if (state.userQuery) query.set("q", state.userQuery);
      if (state.userStatus) query.set("status", state.userStatus);
      const result = await apiRequest(`/api/owner/users?${query}`);
      if (result.users.length === 0) showState(container, t("noUsers"));
      else {
        const rows = result.users.map((user) => {
          const row = make("tr");
          const account = make("td");
          account.append(markLtr(make("strong", "", user.username)));
          account.append(accountMeta(user.id, user.status, user.created_at));
          row.append(account, markLtr(make("td", "", user.email)));
          row.append(markLtr(make("td", "", formatDate(user.created_at))));
          row.append(markLtr(make("td", "", formatDate(user.last_login_at))));
          row.append(markLtr(make("td", "", formatNumber(user.coin_balance))));
          row.append(markLtr(make("td", "", formatNumber(user.diamond_balance))));
          row.append(make("td", "", user.roles.map((role) => translateCode(role, "role")).join(", ") || "—"));
          const action = make("td");
          const button = make("button", "owner-table-action", t("manage"));
          button.type = "button";
          button.addEventListener("click", () => selectUser(user.id, "users"));
          action.append(button);
          row.append(action);
          return row;
        });
        container.replaceChildren(buildTable(
          [t("account"), t("email"), t("created"), t("lastActive"), t("coins"), t("diamonds"), t("roles"), t("actions")],
          rows,
        ));
      }
      addPagination(pagination, result.pagination, loadUsers);
    } catch (error) {
      showState(container, error instanceof Error ? error.message : t("failed"), true);
    }
  };

  const addDetailField = (form, label, name, value, maxLength, wide = false, multiline = false) => {
    const field = make("label", wide ? "owner-profile-wide" : "", label);
    const input = document.createElement(multiline ? "textarea" : "input");
    input.name = name;
    input.maxLength = maxLength;
    input.value = typeof value === "string" ? value : "";
    field.append(input);
    form.append(field);
  };

  const renderTransactions = (container, entries) => {
    if (!Array.isArray(entries) || entries.length === 0) {
      showState(container, t("noTransactions"));
      return;
    }
    const rows = entries.map((entry) => {
      const row = make("tr");
      const operation = make("td");
      operation.append(
        `${translateCode(entry.operation_type, "operation")} · `,
        ltrText(`${formatNumber(entry.amount)} ${translateCode(entry.balance_type)}`),
      );
      row.append(operation);
      row.append(markLtr(make("td", "", `${formatNumber(entry.balance_before)} → ${formatNumber(entry.balance_after)} (${formatNumber(entry.total_balance_before)} → ${formatNumber(entry.total_balance_after)})`)));
      row.append(make("td", "", translateCode(entry.source, "source")));
      row.append(markLtr(make("td", "", formatDate(entry.created_at))));
      return row;
    });
    container.replaceChildren(buildTable([t("operation"), t("balance"), t("source"), t("date")], rows));
  };

  const renderDiamondOrders = (container, orders) => {
    if (!Array.isArray(orders) || orders.length === 0) {
      showState(container, t("noDiamondOrders"));
      return;
    }
    const rows = orders.map((order) => {
      const row = make("tr");
      row.append(markLtr(make("td", "", order.order_number)));
      row.append(markLtr(make("td", "", order.player_id)));
      row.append(markLtr(make("td", "", `${formatNumber(order.diamond_amount)} · ${order.price} ${order.currency_code}`)));
      row.append(make("td", "", translateCode(order.order_status)));
      row.append(make("td", "", translateCode(order.payment_status)));
      row.append(make("td", "", translateCode(order.delivery_status)));
      row.append(markLtr(make("td", "", order.payment_reference || "—")));
      row.append(markLtr(make("td", "", formatDate(order.created_at))));
      return row;
    });
    container.replaceChildren(buildTable(
      [
        t("orderNumber"),
        t("playerId"),
        t("diamondAmount"),
        t("status"),
        t("paymentStatus"),
        t("deliveryStatus"),
        t("paymentReference"),
        t("date"),
      ],
      rows,
    ));
  };

  const renderUserDetails = (user) => {
    const container = document.querySelector("[data-owner-user-detail]");
    const walletAccount = document.querySelector("[data-owner-wallet-account]");
    const ledger = document.querySelector("[data-owner-ledger]");
    if (!(container instanceof HTMLElement) || !(walletAccount instanceof HTMLElement) || !(ledger instanceof HTMLElement)) return;
    const card = make("div");
    const heading = make("div", "owner-detail-heading");
    const identity = make("div");
    const userHeading = make("h3");
    userHeading.append(ltrText(user.username), ` · ${t("account")} `, ltrText(`#${user.id}`));
    identity.append(userHeading);
    const userMeta = make("p");
    userMeta.append(
      ltrText(user.email),
      ` · ${t(user.email_verified ? "verified" : "notVerified")} · ${t("lastActive")}: `,
      ltrText(formatDate(user.last_login_at)),
    );
    identity.append(userMeta);
    heading.append(identity, make("span", "admin-status-pill", translateCode(user.status)));
    card.append(heading);
    const grid = make("div", "owner-detail-grid");

    const profileSection = make("section");
    profileSection.append(make("h4", "", t("profile")));
    const profileForm = make("form", "owner-profile-form");
    profileForm.dataset.ownerProfile = String(user.id);
    addDetailField(profileForm, t("fullName"), "full_name", user.full_name, 150);
    addDetailField(profileForm, t("displayName"), "display_name", user.display_name, 80);
    addDetailField(profileForm, t("country"), "country", user.country, 80);
    addDetailField(profileForm, t("bio"), "bio", user.bio, 2000, true, true);
    const save = make("button", "admin-secondary-button", t("saveProfile"));
    save.type = "submit";
    profileForm.append(save);
    profileSection.append(profileForm);

    const accessSection = make("section");
    accessSection.append(make("h4", "", t("accessRoles")));
    const roleList = make("div", "owner-role-list");
    user.roles.forEach((role) => roleList.append(make("span", "owner-role-pill", translateCode(role, "role"))));
    accessSection.append(roleList);
    if (user.roles.includes("owner")) {
      accessSection.append(make("p", "owner-warning", t("ownerProtected")));
    } else {
      const roleForm = make("form", "owner-role-form");
      roleForm.dataset.ownerRoles = String(user.id);
      const roleLabel = make("label", "", t("selectRole"));
      const roleSelect = document.createElement("select");
      roleSelect.name = "role";
      ["moderator", "game_admin", "support_admin", "super_admin"].forEach((role) => {
        const option = make("option", "", translateCode(role, "role"));
        option.value = role;
        roleSelect.append(option);
      });
      roleLabel.append(roleSelect);
      const operationLabel = make("label", "", t("accessRoles"));
      const operation = document.createElement("select");
      operation.name = "operation";
      [["grant", t("grant")], ["revoke", t("revoke")]].forEach(([value, label]) => {
        const option = make("option", "", label);
        option.value = value;
        operation.append(option);
      });
      operationLabel.append(operation);
      const submit = make("button", "admin-secondary-button", t("saveProfile"));
      submit.type = "submit";
      roleForm.append(roleLabel, operationLabel, submit);
      accessSection.append(roleForm);
    }
    if (user.id === currentUserId) {
      accessSection.append(make("p", "owner-warning", t("cannotSelf")));
    } else if (!user.roles.includes("owner")) {
      const statusActions = make("div", "owner-status-actions");
      const statusButton = make(
        user.status === "active" ? "button" : "button",
        user.status === "active" ? "admin-danger-button" : "admin-primary-button",
        user.status === "active" ? t("suspend") : t("activate"),
      );
      statusButton.type = "button";
      statusButton.dataset.ownerStatus = String(user.id);
      statusButton.dataset.nextStatus = user.status === "active" ? "suspended" : "active";
      statusActions.append(statusButton);
      accessSection.append(make("h4", "", t("accountStatus")), statusActions);
    }

    const walletSection = make("section");
    walletSection.append(make("h4", "", t("walletAccount")));
    const walletSummary = make("div", "owner-wallet-summary");
    for (const [label, value] of [
      [t("coinBalance"), user.coin_balance ?? 0],
      [t("purchased"), user.purchased_coin_balance ?? 0],
      [t("earned"), user.earned_coin_balance ?? 0],
      [t("diamondBalance"), user.diamond_balance ?? 0],
    ]) {
      const item = make("span");
      item.append(`${label}: `, ltrText(formatNumber(value)));
      walletSummary.append(item);
    }
    walletSection.append(walletSummary);

    const activitySection = make("section");
    activitySection.append(make("h4", "", t("transactions")));
    const transactionPreview = make("div");
    renderTransactions(transactionPreview, user.recent_transactions);
    activitySection.append(transactionPreview);

    const ordersSection = make("section");
    ordersSection.append(make("h4", "", t("diamondOrders")));
    const ordersPreview = make("div");
    renderDiamondOrders(ordersPreview, user.recent_diamond_orders);
    ordersSection.append(ordersPreview);

    const auditSection = make("section");
    auditSection.append(make("h4", "", t("recentActivity")));
    const auditRows = Array.isArray(user.recent_admin_activity) ? user.recent_admin_activity : [];
    if (auditRows.length === 0) {
      auditSection.append(make("p", "admin-state", t("noActivity")));
    } else {
      const list = make("div", "owner-compact-list");
      auditRows.forEach((entry) => {
        const row = make("div", "owner-compact-item");
        const description = make("span");
        const title = make("strong");
        title.append(
          `${translateCode(entry.action_type, "action")} · `,
          ltrText(entry.actor_username ?? t("system")),
        );
        description.append(title);
        const details = entry.payload
          ? markLtr(make("small", "", JSON.stringify(entry.payload)))
          : make("small");
        if (!entry.payload) {
          details.append(`${translateCode(entry.entity_type, "entity")} `, ltrText(`#${entry.entity_id ?? "—"}`));
        }
        description.append(details);
        row.append(description, markLtr(make("span", "", formatDate(entry.created_at))));
        list.append(row);
      });
      auditSection.append(list);
    }

    grid.append(profileSection, accessSection, walletSection, activitySection, ordersSection, auditSection);
    card.append(grid);
    container.replaceChildren(card);
    const walletCaption = make("p", "admin-state");
    walletCaption.append(
      ltrText(user.username),
      ` · ${t("account")} `,
      ltrText(`#${user.id}`),
      ` · `,
      ltrText(`${formatNumber(user.coin_balance)} ${t("coins")}`),
    );
    walletAccount.replaceChildren(walletCaption);
    renderTransactions(ledger, user.recent_transactions);
    state.selectedUser = user;
  };

  const selectUser = async (userId, section = "users") => {
    openSection(section);
    const container = document.querySelector("[data-owner-user-detail]");
    if (!(container instanceof HTMLElement)) return;
    showState(container, t("loading"));
    try {
      const { user } = await apiRequest(`/api/owner/users/${userId}`);
      renderUserDetails(user);
    } catch (error) {
      showState(container, error instanceof Error ? error.message : t("failed"), true);
    }
  };

  const loadWalletChoices = async (query = "") => {
    const picker = document.querySelector("[data-owner-wallet-picker]");
    if (!(picker instanceof HTMLSelectElement)) return;
    picker.disabled = true;
    try {
      const params = new URLSearchParams({ page: "1", sort: "username", per_page: "100" });
      if (query.trim()) params.set("q", query.trim());
      const result = await apiRequest(`/api/owner/users?${params}`);
      picker.replaceChildren();
      const empty = make("option", "", t("chooseWalletAccount"));
      empty.value = "";
      picker.append(empty);
      result.users.forEach((user) => {
        const option = make("option", "", `${user.username} · #${user.id} · ${formatNumber(user.coin_balance)} Coins`);
        option.value = String(user.id);
        if (state.selectedUser?.id === user.id) option.selected = true;
        picker.append(option);
      });
      if (result.users.length === 0) {
        const noResult = make("option", "", t("noResults"));
        noResult.value = "";
        noResult.disabled = true;
        picker.append(noResult);
      }
    } catch (error) {
      toast(error instanceof Error ? error.message : t("failed"), true);
    } finally {
      picker.disabled = false;
    }
  };

  const loadActivity = async (page = state.activityPage) => {
    const container = document.querySelector("[data-owner-activity]");
    const pagination = document.querySelector("[data-owner-activity-pagination]");
    if (!(container instanceof HTMLElement) || !(pagination instanceof HTMLElement)) return;
    state.activityPage = page;
    showState(container, t("loading"));
    try {
      const query = new URLSearchParams({ page: String(page) });
      if (state.activityQuery) query.set("q", state.activityQuery);
      const result = await apiRequest(`/api/owner/activity?${query}`);
      if (result.entries.length === 0) showState(container, t("noActivity"));
      else {
        const rows = result.entries.map((entry) => {
          const row = make("tr");
          const actor = make("td");
          actor.append(ltrText(entry.actor_username ?? t("system")), ` · ${translateCode(entry.actor_role, "role")}`);
          row.append(actor);
          row.append(make("td", "", translateCode(entry.action_type, "action")));
          const entity = make("td");
          entity.append(`${translateCode(entry.entity_type, "entity")} `, ltrText(`#${entry.entity_id ?? "—"}`));
          row.append(entity);
          const metadata = markLtr(make("td", "owner-metadata", entry.payload ? JSON.stringify(entry.payload) : "—"));
          metadata.title = metadata.textContent;
          row.append(metadata);
          row.append(markLtr(make("td", "", entry.ip_address ?? "—")));
          row.append(markLtr(make("td", "", formatDate(entry.created_at))));
          return row;
        });
        container.replaceChildren(buildTable(
          [t("actor"), t("operation"), t("entity"), t("metadata"), t("ipAddress"), t("time")],
          rows,
        ));
      }
      addPagination(pagination, result.pagination, loadActivity);
    } catch (error) {
      showState(container, error instanceof Error ? error.message : t("failed"), true);
    }
  };

  const renderGames = (games) => {
    const container = document.querySelector("[data-owner-games]");
    if (!(container instanceof HTMLElement)) return;
    if (!Array.isArray(games) || games.length === 0) {
      showState(container, t("noResults"));
      return;
    }
    const cards = games.map((game) => {
      const card = make("article", "owner-game-card");
      const heading = make("div", "admin-module-heading");
      heading.append(make("h3", "", localizeGameName(game)), make("span", "admin-status-pill", translateCode(game.status)));
      card.append(heading);
      const form = make("form", "owner-game-form");
      form.dataset.ownerGame = String(game.id);
      const addField = (labelText, name, value, type = "text", max = null) => {
        const label = make("label", "", labelText);
        const input = document.createElement(type === "textarea" ? "textarea" : "input");
        if (type !== "textarea") input.type = type;
        input.name = name;
        input.value = value ?? "";
        if (max !== null) input.maxLength = max;
        if (name === "name") input.dir = "ltr";
        if (type === "number") {
          input.min = name === "free_attempt_limit" ? "0" : "1";
          input.max = "100000000";
          input.step = "1";
          input.required = true;
          input.dir = "ltr";
        }
        label.append(input);
        form.append(label);
      };
      addField(t("gameName"), "name", game.name, "text", 100);
      addField(t("gameDescription"), "description", game.description ?? "", "textarea", 2000);
      addField(t("dailyFreeAttempts"), "free_attempt_limit", game.free_attempt_limit, "number");
      addField(t("winReward"), "reward_amount_coins", game.reward_amount_coins, "number");
      const statusLabel = make("label", "", t("gameState"));
      const status = document.createElement("select");
      status.name = "status";
      ["active", "disabled", "maintenance"].forEach((value) => {
        const option = make("option", "", translateCode(value));
        option.value = value;
        option.selected = value === game.status;
        status.append(option);
      });
      statusLabel.append(status);
      form.append(statusLabel);
      const submit = make("button", "admin-primary-button", t("saveGame"));
      submit.type = "submit";
      form.append(submit);
      card.append(form);
      return card;
    });
    container.replaceChildren(...cards);
  };

  const loadGames = async () => {
    const container = document.querySelector("[data-owner-games]");
    if (!(container instanceof HTMLElement)) return;
    showState(container, t("loading"));
    try {
      const { games } = await apiRequest("/api/owner/games");
      state.games = games;
      renderGames(state.games);
    } catch (error) {
      showState(container, error instanceof Error ? error.message : t("failed"), true);
    }
  };


  const SITE_MEDIA_CATALOG = [
    { key: "media_home_hero", cat: "hero", en: "Home Hero Banner", ar: "صورة الواجهة الرئيسية", def: "neno/WhatsApp%20Image%202026-10-29%20at%203.08.15%20PM%20(1).webp" },
    { key: "media_about_portrait", cat: "hero", en: "About NEEN Portrait", ar: "صورة صفحة عن نين", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.14%20PM.webp" },
    { key: "media_default_avatar", cat: "hero", en: "Default Player Avatar", ar: "الصورة الرمزية الافتراضية", def: "icone/snoop.webp" },
    { key: "media_coin_icon", cat: "hero", en: "NEEN Gold Coin Icon", ar: "أيقونة عملة NEEN Coins", def: "assets/hero-loot/gold-coin.webp" },
    { key: "media_events_banner_1", cat: "hero", en: "Events Hub Frame 1", ar: "صورة مركز الفعاليات 1", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(1).webp" },
    { key: "media_events_banner_2", cat: "hero", en: "Events Hub Frame 2", ar: "صورة مركز الفعاليات 2", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.17%20PM.webp" },
    { key: "media_game_sunny_island", cat: "games", en: "Game: Sunny Island", ar: "لعبة: جزيرة صني", def: "assets/hero-loot/snoop-sticker-01.webp" },
    { key: "media_game_vault_cracker", cat: "games", en: "Game: Target Rush", ar: "لعبة: تحدي الأهداف السريع", def: "Target%20Rush/NEEN_Target_Rush_01_Start_Screen.png" },
    { key: "media_game_loot_sequence", cat: "games", en: "Game: Loot Sequence", ar: "لعبة: تسلسل اللوت", def: "assets/hero-loot/snoop-sticker-06.webp" },
    { key: "media_game_memory_match", cat: "games", en: "Game: Memory Match", ar: "لعبة: تطابق الذاكرة", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.14%20PM%20(3).webp" },
    { key: "media_game_picture_jigsaw", cat: "games", en: "Game: Picture Jigsaw", ar: "لعبة: تركيب الصورة", def: "assets/Picture%20Jigsaw%20pic/WhatsApp%20Image%202026-11-07%20at%208.29.09%20PM.webp" },
    { key: "media_game_memory_match_01", cat: "games", en: "Memory Match image 01", ar: "تطابق الذاكرة - صورة 1", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.14%20PM.webp" },
    { key: "media_game_memory_match_02", cat: "games", en: "Memory Match image 02", ar: "تطابق الذاكرة - صورة 2", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.14%20PM%20(1).webp" },
    { key: "media_game_memory_match_03", cat: "games", en: "Memory Match image 03", ar: "تطابق الذاكرة - صورة 3", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.14%20PM%20(2).webp" },
    { key: "media_game_memory_match_04", cat: "games", en: "Memory Match image 04", ar: "تطابق الذاكرة - صورة 4", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.14%20PM%20(3).webp" },
    { key: "media_game_memory_match_05", cat: "games", en: "Memory Match image 05", ar: "تطابق الذاكرة - صورة 5", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.14%20PM%20(4).webp" },
    { key: "media_game_memory_match_06", cat: "games", en: "Memory Match image 06", ar: "تطابق الذاكرة - صورة 6", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.14%20PM%20(5).webp" },
    { key: "media_game_memory_match_07", cat: "games", en: "Memory Match image 07", ar: "تطابق الذاكرة - صورة 7", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.15%20PM.webp" },
    { key: "media_game_memory_match_08", cat: "games", en: "Memory Match image 08", ar: "تطابق الذاكرة - صورة 8", def: "assets/hero-loot/Memory%20Match%20pic/WhatsApp%20Image%202026-11-07%20at%208.11.15%20PM%20(1).webp" },
    { key: "media_game_picture_jigsaw_01", cat: "games", en: "Picture Jigsaw image 01", ar: "تركيب الصورة - صورة 1", def: "assets/Picture%20Jigsaw%20pic/WhatsApp%20Image%202026-11-07%20at%208.29.09%20PM.webp" },
    { key: "media_game_picture_jigsaw_02", cat: "games", en: "Picture Jigsaw image 02", ar: "تركيب الصورة - صورة 2", def: "assets/Picture%20Jigsaw%20pic/WhatsApp%20Image%202026-11-07%20at%208.32.06%20PM.webp" },
    { key: "media_game_picture_jigsaw_03", cat: "games", en: "Picture Jigsaw image 03", ar: "تركيب الصورة - صورة 3", def: "assets/Picture%20Jigsaw%20pic/WhatsApp%20Image%202026-11-07%20at%208.32.37%20PM.webp" },
    { key: "media_video_poster_1", cat: "videos", en: "Home Video Poster 1", ar: "غلاف الفيديو الرئيسي 1", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(1).webp" },
    { key: "media_video_poster_2", cat: "videos", en: "Home Video Poster 2", ar: "غلاف الفيديو الرئيسي 2", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(2).webp" },
    { key: "media_video_poster_3", cat: "videos", en: "Home Video Poster 3", ar: "غلاف الفيديو الرئيسي 3", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.16%20PM.webp" },
    { key: "media_gallery_01", cat: "gallery", en: "Gallery 01 / Rose Garden", ar: "المعرض 01 / حديقة الورد", def: "neno/WhatsApp%20Image%202026-10-29%20at%203.08.14%20PM.webp" },
    { key: "media_gallery_02", cat: "gallery", en: "Gallery 02 / Garden Swing", ar: "المعرض 02 / أرجوحة الحديقة", def: "neno/WhatsApp%20Image%202026-10-29%20at%203.08.14%20PM%20(1).webp" },
    { key: "media_gallery_03", cat: "gallery", en: "Gallery 03 / Night Lights", ar: "المعرض 03 / أضواء الليل", def: "neno/WhatsApp%20Image%202026-10-29%20at%203.08.15%20PM.webp" },
    { key: "media_gallery_04", cat: "gallery", en: "Gallery 04 / City Drive", ar: "المعرض 04 / جولة المدينة", def: "neno/WhatsApp%20Image%202026-10-29%20at%203.08.15%20PM%20(1).webp" },
    { key: "media_gallery_05", cat: "gallery", en: "Gallery 05 / Moonlit Rooftop", ar: "المعرض 05 / سطح القمر", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.14%20PM.webp" },
    { key: "media_gallery_06", cat: "gallery", en: "Gallery 06 / Neon Crown", ar: "المعرض 06 / تاج النيون", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(1).webp" },
    { key: "media_gallery_07", cat: "gallery", en: "Gallery 07 / City Rooftop", ar: "المعرض 07 / سطح المدينة", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(2).webp" },
    { key: "media_gallery_08", cat: "gallery", en: "Gallery 08 / Sakura Lights", ar: "المعرض 08 / أضواء الساكورا", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(3).webp" },
    { key: "media_gallery_09", cat: "gallery", en: "Gallery 09 / Garden Heart", ar: "المعرض 09 / قلب الحديقة", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(4).webp" },
    { key: "media_gallery_10", cat: "gallery", en: "Gallery 10 / Sunset Glow", ar: "المعرض 10 / وهج الغروب", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(5).webp" },
    { key: "media_gallery_11", cat: "gallery", en: "Gallery 11 / Neon Profile", ar: "المعرض 11 / إطلالة النيون", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM%20(6).webp" },
    { key: "media_gallery_12", cat: "gallery", en: "Gallery 12 / Sunset Heart", ar: "المعرض 12 / قلب الغروب", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.15%20PM.webp" },
    { key: "media_gallery_13", cat: "gallery", en: "Gallery 13 / Neon Wave", ar: "المعرض 13 / تحية النيون", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.16%20PM%20(1).webp" },
    { key: "media_gallery_14", cat: "gallery", en: "Gallery 14 / Sakura Step", ar: "المعرض 14 / خطوة الساكورا", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.16%20PM%20(2).webp" },
    { key: "media_gallery_15", cat: "gallery", en: "Gallery 15 / Violet Ride", ar: "المعرض 15 / جولة بنفسجية", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.16%20PM%20(3).webp" },
    { key: "media_gallery_16", cat: "gallery", en: "Gallery 16 / Nightfall", ar: "المعرض 16 / حلول الليل", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.16%20PM%20(4).webp" },
    { key: "media_gallery_17", cat: "gallery", en: "Gallery 17 / Hearts in Bloom", ar: "المعرض 17 / قلوب متفتحة", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.16%20PM.webp" },
    { key: "media_gallery_18", cat: "gallery", en: "Gallery 18 / Sunset Stance", ar: "المعرض 18 / وقفة الغروب", def: "neno/WhatsApp%20Image%202026-10-29%20at%2011.23.17%20PM.webp" },
  ];

  const uploadMediaFile = async (settingKey, file) => {
    const csrf = await apiRequest("/api/auth/csrf");
    if (typeof csrf.csrf_token !== "string") throw new Error(t("csrfMissing"));
    const body = new FormData();
    body.append("setting_key", settingKey);
    body.append("image", file);
    const response = await fetch("/api/owner/site-media/upload", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "X-CSRF-Token": csrf.csrf_token,
      },
      body,
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error(t("invalidResponse"));
    }
    if (!response.ok || result.success !== true) {
      throw new Error(result.errors?.[0]?.message || (t("requestError") + " (" + response.status + ")"));
    }
    return result.data;
  };

  const renderSiteMedia = () => {
    const grid = document.querySelector("[data-owner-media-grid]");
    if (!(grid instanceof HTMLElement)) return;
    const filter = state.mediaCategory || "all";
    const items = filter === "all"
      ? SITE_MEDIA_CATALOG
      : SITE_MEDIA_CATALOG.filter((item) => item.cat === filter);

    const isSafeMediaUrl = (value) => {
      if (typeof value !== "string" || value.trim() !== value || value === "" || value.includes("\\")) {
        return false;
      }
      let decodedPath;
      try {
        decodedPath = decodeURIComponent(value.split(/[?#]/, 1)[0]);
      } catch {
        return false;
      }
      if (decodedPath.includes("..") || /[\u0000-\u001f]/.test(decodedPath)) return false;
      if (/^uploads\/site\/[A-Za-z0-9._/-]+$/.test(value)) return true;
      if (!value.startsWith("/")) return false;
      try {
        return new URL(value, window.location.origin).origin === window.location.origin;
      } catch {
        return false;
      }
    };

    const cards = items.map((item) => {
      const customUrl = state.siteSettings?.[item.key]?.value;
      const isCustom = isSafeMediaUrl(customUrl);
      const activeSrc = isCustom ? customUrl : item.def;

      const card = make("article", "owner-media-card");
      const thumb = make("div", "owner-media-thumb");
      const img = document.createElement("img");
      img.src = activeSrc;
      img.alt = state.language === "ar" ? item.ar : item.en;
      img.loading = "lazy";
      const badge = make(
        "span",
        isCustom ? "owner-media-badge is-custom" : "owner-media-badge",
        isCustom ? t("mediaBadgeCustom") : t("mediaBadgeOriginal")
      );
      thumb.append(img, badge);

      const meta = make("div", "owner-media-meta");
      meta.append(
        make("strong", "", state.language === "ar" ? item.ar : item.en),
        make("small", "", item.key)
      );

      const fileInput = document.createElement("input");
      fileInput.type = "file";
      fileInput.accept = "image/webp,image/png,image/jpeg";
      fileInput.hidden = true;

      const changeBtn = make("button", "admin-primary-button", t("mediaChangeBtn"));
      changeBtn.type = "button";
      changeBtn.addEventListener("click", () => fileInput.click());

      const resetBtn = make("button", "admin-secondary-button", t("mediaResetBtn"));
      resetBtn.type = "button";
      resetBtn.disabled = !isCustom;
      resetBtn.addEventListener("click", async () => {
        if (!window.confirm(t("confirmResetMedia"))) return;
        await withButtonLock(resetBtn, async () => {
          try {
            const data = await mutate("/api/owner/site-media/reset", "POST", { setting_key: item.key });
            state.siteSettings = data.settings || {};
            renderSiteMedia();
            toast(t("saved"));
          } catch (error) {
            toast(error instanceof Error ? error.message : t("failed"), true);
          }
        });
      });

      fileInput.addEventListener("change", async () => {
        const selected = fileInput.files?.[0];
        if (!selected) return;
        if (selected.size > 5 * 1024 * 1024) {
          toast(t("mediaTooLarge"), true);
          fileInput.value = "";
          return;
        }
        changeBtn.disabled = true;
        changeBtn.textContent = t("mediaUploadingBtn");
        try {
          const data = await uploadMediaFile(item.key, selected);
          state.siteSettings = data.settings || {};
          renderSiteMedia();
          toast(t("saved"));
        } catch (error) {
          toast(error instanceof Error ? error.message : t("failed"), true);
        } finally {
          fileInput.value = "";
          changeBtn.disabled = false;
          changeBtn.textContent = t("mediaChangeBtn");
        }
      });

      const actions = make("div", "owner-media-actions");
      actions.append(changeBtn, resetBtn, fileInput);
      card.append(thumb, meta, actions);
      return card;
    });

    grid.replaceChildren(...cards);
  };

  const loadSiteSettings = async () => {
    const form = document.querySelector("[data-owner-site-settings]");
    const updated = document.querySelector("[data-owner-settings-updated]");
    if (!(form instanceof HTMLFormElement) || !(updated instanceof HTMLElement)) return;
    try {
      const { settings } = await apiRequest("/api/owner/site-settings");
      state.siteSettings = settings || {};
      renderSiteMedia();
      for (const key of ["tiktok_url", "site_description"]) {
        const field = form.elements.namedItem(key);
        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
          field.value = settings[key]?.value ?? "";
        }
      }
      updated.textContent = "";
    } catch (error) {
      updated.textContent = error instanceof Error ? error.message : t("failed");
      updated.classList.add("is-error");
    }
  };

  const renderNewsPreview = () => {
    if (!state.newsData) return;
    const preview = document.querySelector("[data-owner-news-preview]");
    if (!(preview instanceof HTMLElement)) return;
    const form = document.querySelector("[data-owner-news-settings]");
    if (!(form instanceof HTMLFormElement)) return;
    const themeInput = form.querySelector('input[name="theme"]:checked');
    const themeId = themeInput instanceof HTMLInputElement ? themeInput.value : state.newsData.settings.theme;
    const customTheme = state.newsData.themes[themeId] || state.newsData.themes.pink_indigo;
    preview.style.setProperty("--owner-news-background", customTheme.background);
    preview.style.setProperty("--owner-news-text", customTheme.text);
    preview.style.setProperty("--owner-news-sender", customTheme.sender);
    preview.style.setProperty("--owner-news-separator", customTheme.separator);
    preview.style.setProperty("--owner-news-glow", customTheme.glow);
    const speedInput = form.elements.namedItem("speed_seconds");
    if (speedInput instanceof HTMLInputElement) {
      preview.style.setProperty("--owner-news-speed", `${speedInput.value}s`);
      const speedLabel = document.querySelector("[data-news-speed-label]");
      if (speedLabel) speedLabel.textContent = `${speedInput.value} seconds`;
    }
  };

  const renderNewsMessages = () => {
    const container = document.querySelector("[data-owner-news-messages]");
    if (!(container instanceof HTMLElement) || !state.newsData) return;
    container.replaceChildren();
    if (!state.newsData.messages.length) {
      showState(container, "No news messages have been submitted.");
      return;
    }

    state.newsData.messages.forEach((item) => {
      const card = make("article", "owner-news-message-card");
      card.dataset.status = item.status;

      const heading = make("div", "owner-news-message-heading");
      heading.append(make("strong", "", `#${item.id}${item.source === "Owner" ? " · Owner Message" : " · Visitor"}`));
      const status = make("span", `owner-news-status is-${item.status.toLowerCase()}`, item.status);
      if (item.status === "Approved") status.textContent += item.display_active ? " · Visible" : " · Paused";
      heading.append(status);

      const senderInput = make("input", "owner-news-edit-input");
      senderInput.value = item.sender_name;
      senderInput.maxLength = 40;
      senderInput.setAttribute("aria-label", "Sender name");
      const messageInput = make("textarea", "owner-news-edit-input");
      messageInput.value = item.message;
      messageInput.maxLength = 280;
      messageInput.rows = 2;
      messageInput.setAttribute("aria-label", "News message");

      const actions = make("div", "owner-news-actions");
      const save = make("button", "admin-secondary-button", "Save edit");
      save.type = "button";
      save.addEventListener("click", async () => {
        await withButtonLock(save, async () => {
          try {
            await mutate(`/api/owner/news-ticker/messages/${item.id}`, "PATCH", {
              sender_name: senderInput.value,
              message: messageInput.value,
            });
            toast("News message saved.");
            await loadNewsTicker();
          } catch (error) {
            toast(error instanceof Error ? error.message : t("failed"), true);
          }
        });
      });

      const actionButton = (label, action, className = "admin-secondary-button") => {
        const button = make("button", className, label);
        button.type = "button";
        button.addEventListener("click", async () => {
          if (action === "delete" && !window.confirm(t("confirmDeleteNews"))) return;
          await withButtonLock(button, async () => {
            try {
              await mutate(`/api/owner/news-ticker/messages/${item.id}/${action}`, "POST", {});
              toast(`${label} completed.`);
              await loadNewsTicker();
            } catch (error) {
              toast(error instanceof Error ? error.message : t("failed"), true);
            }
          });
        });
        return button;
      };

      actions.append(save);
      if (item.status === "Pending") {
        actions.append(actionButton("Approve", "approve", "admin-primary-button"));
        actions.append(actionButton("Reject", "reject"));
      } else if (item.status === "Approved") {
        actions.append(item.display_active
          ? actionButton("Pause", "pause")
          : actionButton("Resume", "resume", "admin-primary-button"));
      } else if (item.status !== "Deleted") {
        actions.append(actionButton("Approve", "approve", "admin-primary-button"));
      }
      actions.append(actionButton("Delete", "delete", "admin-danger-button"));

      card.append(heading, senderInput, messageInput, actions);
      container.append(card);
    });
  };

  const loadNewsTicker = async () => {
    const form = document.querySelector("[data-owner-news-settings]");
    const themesContainer = document.querySelector("[data-owner-news-themes]");
    if (!(form instanceof HTMLFormElement) || !(themesContainer instanceof HTMLElement)) return;
    try {
      state.newsData = await apiRequest("/api/owner/news-ticker");
      if (state.newsData.migration_ready === false) {
        const container = document.querySelector("[data-owner-news-messages]");
        if (container instanceof HTMLElement) {
          showState(container, state.newsData.error || "News Ticker database migration is required.", true);
        }
        return;
      }
      const settings = state.newsData.settings;
      const enabled = form.elements.namedItem("enabled");
      if (enabled instanceof HTMLInputElement) enabled.checked = settings.enabled;
      const speed = form.elements.namedItem("speed_seconds");
      if (speed instanceof HTMLInputElement) speed.value = String(settings.speed_seconds);

      themesContainer.replaceChildren();
      Object.entries(state.newsData.themes).forEach(([themeId, colors]) => {
        const label = make("label", `owner-news-theme-option theme-${themeId}`);
        const radio = document.createElement("input");
        radio.type = "radio";
        radio.name = "theme";
        radio.value = themeId;
        radio.checked = themeId === settings.theme;
        radio.addEventListener("change", renderNewsPreview);
        const themeNames = {
          pink_indigo: "Pink & Indigo",
          electric_purple: "Electric Purple",
          cyber_blue: "Cyber Blue",
          combat_red: "Combat Red",
          legendary_gold: "Legendary Gold",
        };
        const name = make("strong", "", themeNames[themeId] || themeId);
        const swatches = make("span", "owner-news-theme-swatches");
        [colors.background, colors.text, colors.sender, colors.separator, colors.glow].forEach((color) => {
          const swatch = make("i", "");
          swatch.style.background = color;
          swatches.append(swatch);
        });
        label.append(radio, name, swatches);
        themesContainer.append(label);
      });

      renderNewsPreview();
      renderNewsMessages();
    } catch (error) {
      const message = error instanceof Error ? error.message : t("failed");
      const container = document.querySelector("[data-owner-news-messages]");
      if (container instanceof HTMLElement) showState(container, `Could not load news messages: ${message}`, true);
      toast(message, true);
    }
  };

  const newsForm = document.querySelector("[data-owner-news-settings]");
  if (newsForm instanceof HTMLFormElement) {
    newsForm.addEventListener("input", renderNewsPreview);
    newsForm.addEventListener("change", renderNewsPreview);
    newsForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!state.newsData) return;
      const themeInput = newsForm.querySelector('input[name="theme"]:checked');
      const themeId = themeInput instanceof HTMLInputElement ? themeInput.value : state.newsData.settings.theme;
      const colors = state.newsData.themes[themeId];
      const enabledInput = newsForm.elements.namedItem("enabled");
      const speedInput = newsForm.elements.namedItem("speed_seconds");
      const unlock = lockFormSubmit(newsForm);
      if (!unlock) return;
      try {
        await mutate("/api/owner/news-ticker", "PATCH", {
          enabled: enabledInput instanceof HTMLInputElement ? enabledInput.checked : true,
          theme: themeId,
          speed_seconds: speedInput instanceof HTMLInputElement ? Number(speedInput.value) : 55,
          colors,
        });
        toast("News ticker settings saved.");
        await loadNewsTicker();
      } catch (error) {
        toast(error instanceof Error ? error.message : t("failed"), true);
      } finally {
        unlock();
      }
    });
  }

  const publishForm = document.querySelector("[data-owner-news-publish]");
  if (publishForm instanceof HTMLFormElement) {
    const preview = publishForm.querySelector("[data-owner-news-publish-preview]");
    const updatePublishPreview = () => {
      if (!(preview instanceof HTMLElement)) return;
      const sender = publishForm.elements.namedItem("sender_name");
      const message = publishForm.elements.namedItem("message");
      const senderValue = sender instanceof HTMLInputElement ? sender.value.trim() : "NEEN";
      const messageValue = message instanceof HTMLTextAreaElement ? message.value.trim() : "";
      preview.replaceChildren();
      preview.append(make("strong", "", `${senderValue || "NEEN"}:`), make("span", "", messageValue || "Write a message to preview it."));
    };
    publishForm.addEventListener("input", updatePublishPreview);
  }

  const publishFormSubmit = publishForm;
  if (publishFormSubmit instanceof HTMLFormElement) {
    publishFormSubmit.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!publishFormSubmit.reportValidity()) return;
      const unlock = lockFormSubmit(publishFormSubmit);
      if (!unlock) return;
      const values = new FormData(publishFormSubmit);
      try {
        await mutate("/api/owner/news-ticker/messages/publish", "POST", {
          sender_name: String(values.get("sender_name") ?? "NEEN").trim(),
          message: String(values.get("message") ?? "").trim(),
        });
        publishFormSubmit.reset();
        const preview = publishFormSubmit.querySelector("[data-owner-news-publish-preview]");
        if (preview instanceof HTMLElement) preview.replaceChildren(make("strong", "", "NEEN:"), make("span", "", "Write a message to preview it."));
        toast("Owner message published directly.");
        await loadNewsTicker();
      } catch (error) {
        toast(error instanceof Error ? error.message : t("failed"), true);
      } finally {
        unlock();
      }
    });
  }

  document.querySelector("[data-owner-news-refresh]")?.addEventListener("click", async () => {
    const container = document.querySelector("[data-owner-news-messages]");
    if (container instanceof HTMLElement) showState(container, "Loading news messages…");
    await loadNewsTicker();
  });

  const refreshSelected = async () => {
    if (!state.selectedUser) return;
    await selectUser(state.selectedUser.id, "users");
  };

  const mediaFilter = document.querySelector("[data-owner-media-filter]");
  if (mediaFilter instanceof HTMLSelectElement) {
    mediaFilter.addEventListener("change", () => {
      state.mediaCategory = mediaFilter.value || "all";
      renderSiteMedia();
    });
  }
  navItems.forEach((item) => item.addEventListener("click", () => openSection(item.dataset.section)));
  document.querySelectorAll("[data-open-section]").forEach((button) => {
    button.addEventListener("click", () => openSection(button.dataset.openSection));
  });

  const userForm = document.querySelector("[data-owner-user-search]");
  if (userForm instanceof HTMLFormElement) {
    userForm.addEventListener("submit", (event) => {
      event.preventDefault();
      const values = new FormData(userForm);
      state.userQuery = String(values.get("q") ?? "").trim();
      state.userStatus = String(values.get("status") ?? "");
      state.userSort = String(values.get("sort") ?? "newest");
      loadUsers(1);
    });
  }
  document.querySelector("[data-owner-users-refresh]")?.addEventListener("click", () => {
    if (userForm instanceof HTMLFormElement) userForm.reset();
    state.userQuery = "";
    state.userStatus = "";
    state.userSort = "newest";
    loadUsers(1);
  });

  const walletSearch = document.querySelector("[data-owner-wallet-search]");
  if (walletSearch instanceof HTMLFormElement) {
    walletSearch.addEventListener("submit", (event) => {
      event.preventDefault();
      const query = String(new FormData(walletSearch).get("q") ?? "").trim();
      loadWalletChoices(query);
    });
  }

  const walletPicker = document.querySelector("[data-owner-wallet-picker]");
  if (walletPicker instanceof HTMLSelectElement) {
    walletPicker.addEventListener("change", async () => {
      const userId = Number(walletPicker.value);
      if (!Number.isSafeInteger(userId) || userId < 1) return;
      const container = document.querySelector("[data-owner-user-detail]");
      if (container instanceof HTMLElement) showState(container, t("loading"));
      try {
        const { user } = await apiRequest(`/api/owner/users/${userId}`);
        renderUserDetails(user);
      } catch (error) {
        if (container instanceof HTMLElement) showState(container, error instanceof Error ? error.message : t("failed"), true);
      }
    });
  }

  const activityForm = document.querySelector("[data-owner-activity-search]");
  if (activityForm instanceof HTMLFormElement) {
    activityForm.addEventListener("submit", (event) => {
      event.preventDefault();
      state.activityQuery = String(new FormData(activityForm).get("q") ?? "").trim();
      loadActivity(1);
    });
  }
  document.querySelector("[data-owner-activity-refresh]")?.addEventListener("click", () => {
    if (activityForm instanceof HTMLFormElement) activityForm.reset();
    state.activityQuery = "";
    loadActivity(1);
  });

  document.addEventListener("submit", async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.matches("[data-owner-profile]")) {
      event.preventDefault();
      const unlock = lockFormSubmit(form);
      if (!unlock) return;
      const fields = Object.fromEntries(new FormData(form).entries());
      const userId = form.dataset.ownerProfile;
      try {
        await mutate(`/api/owner/users/${userId}/profile`, "PATCH", fields);
        toast(t("saved"));
        await Promise.all([refreshSelected(), loadOverview()]);
      } catch (error) {
        toast(error instanceof Error ? error.message : t("failed"), true);
      } finally {
        unlock();
      }
    }
    if (form.matches("[data-owner-roles]")) {
      event.preventDefault();
      if (!window.confirm(t("confirmRole"))) return;
      const unlock = lockFormSubmit(form);
      if (!unlock) return;
      const fields = new FormData(form);
      try {
        await mutate(`/api/owner/users/${form.dataset.ownerRoles}/roles`, "PATCH", {
          role: String(fields.get("role")),
          operation: String(fields.get("operation")),
        });
        toast(t("saved"));
        await Promise.all([refreshSelected(), loadUsers(), loadOverview()]);
      } catch (error) {
        toast(error instanceof Error ? error.message : t("failed"), true);
      } finally {
        unlock();
      }
    }
    if (form.matches("[data-owner-adjustment]")) {
      event.preventDefault();
      if (!state.selectedUser) {
        toast(t("selectWalletUser"), true);
        return;
      }
      if (!window.confirm(t("confirmAdjustment"))) return;
      const unlock = lockFormSubmit(form);
      if (!unlock) return;
      const fields = new FormData(form);
      try {
        await mutate(`/api/admin/wallets/${state.selectedUser.id}/adjustments`, "POST", {
          direction: String(fields.get("direction")),
          balance_type: String(fields.get("balance_type")),
          amount: Number(fields.get("amount")),
          reason: String(fields.get("reason")).trim(),
          idempotency_key: `owner-wallet-${crypto.randomUUID()}`,
        });
        form.reset();
        toast(t("saved"));
        await Promise.all([refreshSelected(), loadOverview()]);
      } catch (error) {
        toast(error instanceof Error ? error.message : t("failed"), true);
      } finally {
        unlock();
      }
    }
    if (form.matches("[data-owner-game]")) {
      event.preventDefault();
      if (!window.confirm(t("confirmGame"))) return;
      const unlock = lockFormSubmit(form);
      if (!unlock) return;
      const fields = new FormData(form);
      try {
        await mutate(`/api/owner/games/${form.dataset.ownerGame}`, "PATCH", {
          name: String(fields.get("name")).trim(),
          description: String(fields.get("description")).trim(),
          status: String(fields.get("status")),
          free_attempt_limit: Number(fields.get("free_attempt_limit")),
          reward_amount_coins: Number(fields.get("reward_amount_coins")),
        });
        toast(t("saved"));
        await Promise.all([loadGames(), loadOverview()]);
      } catch (error) {
        toast(error instanceof Error ? error.message : t("failed"), true);
      } finally {
        unlock();
      }
    }
    if (form.matches("[data-owner-site-settings]")) {
      event.preventDefault();
      const unlock = lockFormSubmit(form);
      if (!unlock) return;
      const fields = new FormData(form);
      try {
        await mutate("/api/owner/site-settings", "PATCH", {
          tiktok_url: String(fields.get("tiktok_url")).trim(),
          site_description: String(fields.get("site_description")).trim(),
        });
        toast(t("saved"));
        await loadSiteSettings();
      } catch (error) {
        toast(error instanceof Error ? error.message : t("failed"), true);
      } finally {
        unlock();
      }
    }
  });

  document.addEventListener("click", async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const button = target.closest("[data-owner-status]");
    if (!(button instanceof HTMLButtonElement)) return;
    const status = button.dataset.nextStatus;
    const question = status === "suspended" ? t("confirmSuspend") : t("confirmActivate");
    if (!window.confirm(question)) return;
    button.disabled = true;
    try {
      await mutate(`/api/owner/users/${button.dataset.ownerStatus}/status`, "PATCH", { status });
      toast(t("saved"));
      await Promise.all([refreshSelected(), loadUsers(), loadOverview()]);
    } catch (error) {
      toast(error instanceof Error ? error.message : t("failed"), true);
    } finally {
      button.disabled = false;
    }
  });

  const languageToggle = document.querySelector("[data-language-toggle]");
  const setLanguage = (language) => {
    state.language = language === "ar" ? "ar" : "en";
    document.documentElement.lang = state.language;
    document.documentElement.dir = state.language === "ar" ? "rtl" : "ltr";
    try {
      window.localStorage.setItem(languagePreferenceKey, state.language);
    } catch (error) {
      console.warn("Owner dashboard language preference could not be saved.", error);
    }
    document.querySelectorAll("[data-i18n]").forEach((element) => {
      const key = element.dataset.i18n;
      if (key) element.textContent = t(key);
    });
    document.querySelectorAll("[data-i18n-placeholder]").forEach((element) => {
      const key = element.dataset.i18nPlaceholder;
      if (key && element instanceof HTMLInputElement) element.placeholder = t(key);
    });
    document.querySelectorAll("[data-i18n-aria-label]").forEach((element) => {
      const key = element.dataset.i18nAriaLabel;
      if (key) element.setAttribute("aria-label", t(key));
    });
    if (languageToggle instanceof HTMLButtonElement) {
      languageToggle.textContent = state.language === "en" ? "عربي" : "EN";
    }
    if (state.selectedUser) renderUserDetails(state.selectedUser);
    if (!document.querySelector('[data-panel="news"]').hidden) loadNewsTicker();
    if (!document.querySelector('[data-panel="overview"]').hidden) loadOverview();
    if (!document.querySelector('[data-panel="users"]').hidden) loadUsers(state.userPage);
    if (!document.querySelector('[data-panel="activity"]').hidden) loadActivity(state.activityPage);
    if (!document.querySelector('[data-panel="modules"]').hidden) renderGames(state.games);
    if (!document.querySelector('[data-panel="activity"]').hidden) loadSiteSettings();
  };
  if (languageToggle instanceof HTMLButtonElement) {
    languageToggle.addEventListener("click", () => {
      setLanguage(state.language === "en" ? "ar" : "en");
    });
  }

  document.querySelector("[data-logout]")?.addEventListener("click", async () => {
    try {
      const csrf = await apiRequest("/api/auth/csrf");
      if (typeof csrf.csrf_token !== "string") throw new Error(t("csrfMissing"));
      await apiRequest("/api/auth/logout", {
        method: "POST",
        headers: { "X-CSRF-Token": csrf.csrf_token },
        body: JSON.stringify({}),
      });
      window.location.assign("/account.html?auth=signed-out");
    } catch (error) {
      toast(error instanceof Error ? error.message : t("failed"), true);
    }
  });

  setLanguage(state.language);
})();
