(() => {
  const app = document.querySelector("#admin-app");
  if (!(app instanceof HTMLElement)) return;

  const translations = new Map(Object.entries({
    "NEEN Admin Dashboard": "لوحة إدارة NEEN",
    "Administration navigation": "قائمة الإدارة",
    "NEEN Events Hub": "مركز فعاليات NEEN",
    "CONTROL ROOM": "غرفة التحكم",
    "Dashboard sections": "أقسام لوحة التحكم",
    "Permission required": "الصلاحية مطلوبة",
    "LOCKED": "مقفل",
    "SERVER-VERIFIED SESSION": "جلسة موثقة من الخادم",
    "NEEN / EVENTS & COMMERCE OPS": "NEEN / إدارة الفعاليات والتجارة",
    "Events & Commerce Admin": "إدارة الفعاليات والتجارة",
    "Owner Command Center ↗": "مركز قيادة المالك ↗",
    "Switch dashboard language": "تغيير لغة لوحة التحكم",
    "Sign out": "تسجيل الخروج",
    "Dashboard": "لوحة التحكم",
    "Users": "المستخدمون",
    "Coins & Wallet": "العملات والمحفظة",
    "Coin Orders": "طلبات العملات",
    "Games": "الألعاب",
    "Attendance & Rewards": "الحضور والمكافآت",
    "Admin Users & Permissions": "المشرفون والصلاحيات",
    "Audit Log": "سجل التدقيق",
    "Homepage Rewards": "مكافآت الصفحة الرئيسية",
    "Community Events": "فعاليات المجتمع",
    "Event Voting & Results": "تصويت الفعاليات ونتائجها",
    "Event Suggestions": "اقتراحات الفعاليات",
    "Diamond Packages": "باقات الجواهر",
    "Diamond Recharge Orders": "طلبات شحن الجواهر",
    "Coins setup": "إعداد العملات",
    "OVERVIEW / 01": "نظرة عامة / 01",
    "Live information from permission-checked NEEN services. Unsupported modules are clearly identified.": "معلومات مباشرة من خدمات NEEN المحمية بالصلاحيات. تظهر الوحدات غير المتاحة بوضوح.",
    "Pending orders (latest 100)": "الطلبات المعلّقة (أحدث 100 طلب)",
    "Community events": "فعاليات المجتمع",
    "Pending event suggestions": "اقتراحات الفعاليات المعلّقة",
    "Pending diamond orders": "طلبات الجواهر المعلّقة",
    "Available game catalogs": "كتالوجات الألعاب المتاحة",
    "Recent admin activity": "أحدث نشاط إداري",
    "LATEST OPERATIONS": "أحدث العمليات",
    "Recent activity": "النشاط الأخير",
    "Open audit log": "فتح سجل التدقيق",
    "Loading activity…": "جارٍ تحميل النشاط…",
    "Audit activity is unavailable for this account.": "سجل نشاط التدقيق غير متاح لهذا الحساب.",
    "ACCOUNTS / 02": "الحسابات / 02",
    "Read-only account search. Account state changes are not exposed in this console.": "البحث في الحسابات للعرض فقط. لا يمكن تغيير حالة الحساب من لوحة التحكم هذه.",
    "Search username or user ID": "ابحث باسم المستخدم أو رقم الحساب",
    "Search": "بحث",
    "Show latest": "عرض الأحدث",
    "Search for a user to view account status and effective permissions.": "ابحث عن مستخدم لعرض حالة الحساب والصلاحيات الفعلية.",
    "User directory permission required": "تتطلب قائمة المستخدمين صلاحية",
    "This account does not have view_admin_users access. No account details are exposed.": "لا يملك هذا الحساب صلاحية view_admin_users. لن تظهر تفاصيل الحسابات.",
    "FINANCE / 03": "المالية / 03",
    "Read-only ledger reporting; no direct balance editing is available.": "تقارير سجل المعاملات للعرض فقط؛ لا يمكن تعديل الأرصدة مباشرة.",
    "Search for an account to view its wallet balances.": "ابحث عن حساب لعرض أرصدة محفظته.",
    "Select a wallet to view its recent ledger entries.": "اختر محفظة لعرض أحدث قيودها.",
    "Wallet reporting permission required": "تتطلب تقارير المحافظ صلاحية",
    "This account does not have view_all_wallets access. Direct balance editing remains unavailable.": "لا يملك هذا الحساب صلاحية view_all_wallets. يظل تعديل الأرصدة مباشرة غير متاح.",
    "PURCHASE REVIEW / 04": "مراجعة المشتريات / 04",
    "Approvals use the existing audited order-review service and may credit Purchased Coins. Verify the external payment before approving.": "تستخدم الموافقات خدمة مراجعة الطلبات المسجلة وقد تضيف عملات مشتراة. تحقق من الدفع الخارجي قبل الموافقة.",
    "SERVER QUEUE": "قائمة انتظار الخادم",
    "Awaiting review": "بانتظار المراجعة",
    "Refresh": "تحديث",
    "Select Coin Orders to load the permission-checked queue.": "اختر طلبات العملات لتحميل القائمة المحمية بالصلاحيات.",
    "NEEN ORIGINALS / 05": "ألعاب NEEN الأصلية / 05",
    "Read-only catalogs from the existing game services. This dashboard does not change game rules, attempts, or rewards.": "كتالوجات للعرض فقط من خدمات الألعاب الحالية. لا تغيّر هذه اللوحة قواعد الألعاب أو المحاولات أو المكافآت.",
    "Loading game catalogs…": "جارٍ تحميل كتالوجات الألعاب…",
    "RETENTION / 06": "الاستمرارية / 06",
    "Administrative attendance reporting is not connected": "تقارير الحضور الإدارية غير متصلة",
    "The available attendance endpoint shows only the current account's own cycle. Daily rewards and game rewards remain server-controlled; this console does not alter them.": "تعرض خدمة الحضور المتاحة دورة الحساب الحالي فقط. تظل المكافآت اليومية ومكافآت الألعاب تحت إدارة الخادم ولا تغيّرها هذه اللوحة.",
    "REWARD RULES / 09": "قواعد المكافآت / 09",
    "Claims are disabled until an authorized administrator configures the reward amount and claim window.": "تظل المطالبات معطّلة حتى يضبط مشرف مخوّل قيمة المكافأة ومدة المطالبة.",
    "Loading homepage reward settings…": "جارٍ تحميل إعدادات مكافآت الصفحة الرئيسية…",
    "Earned Coins per claim": "العملات المكتسبة لكل مطالبة",
    "Claim window in seconds": "مدة المطالبة بالثواني",
    "Enable reward claims": "تفعيل المطالبات بالمكافأة",
    "Save reward settings": "حفظ إعدادات المكافأة",
    "COMMUNITY / EVENTS": "المجتمع / الفعاليات",
    "Create, publish, pause, or cancel polls. Voting windows and results are enforced by the API.": "أنشئ استطلاعات الرأي أو انشرها أو أوقفها أو ألغها. يفرض الخادم مواعيد التصويت والنتائج.",
    "EVENT CATALOG": "كتالوج الفعاليات",
    "Managed events": "الفعاليات المُدارة",
    "Loading events…": "جارٍ تحميل الفعاليات…",
    "NEW EVENT": "فعالية جديدة",
    "Create a draft poll": "إنشاء استطلاع كمسودة",
    "Event title": "عنوان الفعالية",
    "Starts at (local time)": "تبدأ في (التوقيت المحلي)",
    "Ends at (local time)": "تنتهي في (التوقيت المحلي)",
    "Description": "الوصف",
    "Poll options (one per line)": "خيارات الاستطلاع (خيار في كل سطر)",
    "Create draft event": "إنشاء فعالية كمسودة",
    "VOTING / RESULTS": "التصويت / النتائج",
    "Voting is controlled by published event dates. Final results are server-calculated and require admin approval.": "يُحدد التصويت وفق مواعيد الفعالية المنشورة. يحسب الخادم النتائج النهائية وتتطلب اعتماد المشرف.",
    "Loading event results…": "جارٍ تحميل نتائج الفعاليات…",
    "COMMUNITY INPUT": "مساهمات المجتمع",
    "Review and record a reason for accepting or rejecting each user suggestion.": "راجع اقتراحات المستخدمين وسجّل سبب قبول كل اقتراح أو رفضه.",
    "Loading pending suggestions…": "جارٍ تحميل الاقتراحات المعلّقة…",
    "TOP-UP CATALOG": "كتالوج الشحن",
    "No package terms are preconfigured. New packages remain inactive until explicitly enabled.": "لا توجد شروط باقات مُعدة مسبقًا. تظل الباقات الجديدة غير نشطة حتى تفعيلها صراحةً.",
    "Package name": "اسم الباقة",
    "Diamond quantity": "عدد الجواهر",
    "Price": "السعر",
    "Currency code": "رمز العملة",
    "Create inactive package": "إنشاء باقة غير نشطة",
    "RECHARGE QUEUE": "قائمة طلبات الشحن",
    "Payment and delivery remain manual until approved providers are configured. Every transition records its administrator and note.": "يظل الدفع والتسليم يدويين حتى إعداد مزودي خدمة معتمدين. يُسجل كل تغيير مع اسم المشرف وملاحظته.",
    "Search order number or Player ID": "ابحث برقم الطلب أو رقم اللاعب",
    "Show all": "عرض الكل",
    "Loading recharge orders…": "جارٍ تحميل طلبات الشحن…",
    "Select an order to view its audit history.": "اختر طلبًا لعرض سجل تدقيقه.",
    "ACCESS CONTROL / 07": "التحكم في الوصول / 07",
    "Admins & Permissions": "المشرفون والصلاحيات",
    "Admin directory permission required": "تتطلب قائمة المشرفين صلاحية",
    "This account does not have view_admin_users access. Role and permission assignment remains restricted to an authorized trusted workflow.": "لا يملك هذا الحساب صلاحية view_admin_users. يظل إسناد الأدوار والصلاحيات محصورًا بإجراء موثوق ومخوّل.",
    "IMMUTABLE HISTORY / 08": "سجل غير قابل للتغيير / 08",
    "Read-only latest 50 operations. Access requires the existing view_audit_logs permission.": "عرض أحدث 50 عملية فقط. يتطلب الوصول صلاحية view_audit_logs.",
    "SERVER RECORDS": "سجلات الخادم",
    "Administrative activity": "النشاط الإداري",
    "Select Audit Log to load records.": "اختر سجل التدقيق لتحميل السجلات.",
    "CONFIGURATION / 09": "الإعدادات / 09",
    "Server-backed package and payment settings. New entries remain inactive unless you explicitly enable them.": "إعدادات الباقات والدفع محفوظة على الخادم. تظل العناصر الجديدة غير نشطة ما لم تفعّلها صراحةً.",
    "PACKAGE CATALOG": "كتالوج الباقات",
    "Coin packages": "باقات العملات",
    "Loading configured packages…": "جارٍ تحميل الباقات المُعدة…",
    "Coins": "العملات",
    "Activate after saving": "تفعيل بعد الحفظ",
    "Create package": "إنشاء باقة",
    "PAYMENT CATALOG": "كتالوج وسائل الدفع",
    "Payment methods": "وسائل الدفع",
    "Loading configured payment methods…": "جارٍ تحميل وسائل الدفع المُعدة…",
    "Method code": "رمز الوسيلة",
    "Display name": "الاسم المعروض",
    "Instructions": "التعليمات",
    "Require payment proof": "طلب إثبات الدفع",
    "Create payment method": "إنشاء وسيلة دفع",
    "No audit entries are available.": "لا توجد سجلات تدقيق متاحة.",
    "No event activity has been recorded.": "لم يُسجل أي نشاط لهذه الفعالية.",
    "Actor": "المنفّذ",
    "Action": "الإجراء",
    "Time (UTC)": "الوقت (UTC)",
    "Loading authorized audit entries…": "جارٍ تحميل سجلات التدقيق المصرح بها…",
    "Audit log could not be loaded.": "تعذر تحميل سجل التدقيق.",
    "SERVER CATALOG": "كتالوج الخادم",
    "NEEN original game": "لعبة أصلية من NEEN",
    "Catalog available": "الكتالوج متاح",
    "reset": "إعادة الضبط",
    "pairs": "أزواج",
    "pieces": "قطع",
    "Read from the public game catalogs": "مقروء من كتالوجات الألعاب العامة",
    "The game catalog service returned an error": "حدث خطأ في خدمة كتالوج الألعاب",
    "Required review reason": "سبب المراجعة مطلوب",
    "Review reason for order": "سبب مراجعة الطلب",
    "Approve": "موافقة",
    "Reject": "رفض",
    "Approve this order? The server will credit its configured Purchased Coins amount.": "هل تريد الموافقة على هذا الطلب؟ سيضيف الخادم عدد العملات المشتراة المحدد له.",
    "No payment reference": "لا يوجد مرجع دفع",
    "Payment evidence": "إثبات الدفع",
    "Submitted": "تاريخ الإرسال",
    "Review": "مراجعة",
    "Name": "الاسم",
    "Terms": "الشروط",
    "Status": "الحالة",
    "Active": "نشط",
    "Inactive": "غير نشط",
    "Deactivate": "إيقاف",
    "Activate": "تفعيل",
    "Proof required": "إثبات الدفع مطلوب",
    "Proof optional": "إثبات الدفع اختياري",
    "Package": "الباقة",
    "Action": "الإجراء",
    "No packages are configured.": "لا توجد باقات مُعدة.",
    "No methods are configured.": "لا توجد وسائل دفع مُعدة.",
    "Loading server catalog…": "جارٍ تحميل كتالوج الخادم…",
    "Edit event details": "تعديل تفاصيل الفعالية",
    "Publish voting": "نشر التصويت",
    "Pause voting": "إيقاف التصويت مؤقتًا",
    "Approve results": "اعتماد النتائج",
    "Cancel event": "إلغاء الفعالية",
    "Activity log": "سجل النشاط",
    "Save event changes": "حفظ تغييرات الفعالية",
    "Award an eligible participant": "منح مكافأة لمشارك مستحق",
    "Participant user ID": "رقم حساب المشارك",
    "Earned Coins": "العملات المكتسبة",
    "Reason": "السبب",
    "Issue reward": "إصدار المكافأة",
    "Loading events from the database…": "جارٍ تحميل الفعاليات من قاعدة البيانات…",
    "No community events have been created.": "لم يتم إنشاء فعاليات مجتمعية.",
    "No voting events or results are available.": "لا توجد فعاليات تصويت أو نتائج متاحة.",
    "Loading pending suggestions…": "جارٍ تحميل الاقتراحات المعلّقة…",
    "There are no suggestions waiting for review.": "لا توجد اقتراحات بانتظار المراجعة.",
    "Optional reviewer note": "ملاحظة اختيارية للمراجع",
    "Accept": "قبول",
    "Title": "العنوان",
    "Edit event details": "تعديل تفاصيل الفعالية",
    "Save event changes": "حفظ تغييرات الفعالية",
    "Submitted by": "مقدّم من",
    "vote(s)": "تصويت",
    "Voting:": "التصويت:",
    "Publish voting": "نشر التصويت",
    "Pause voting": "إيقاف التصويت مؤقتًا",
    "Approve results": "اعتماد النتائج",
    "Cancel event": "إلغاء الفعالية",
    "No matching diamond recharge orders.": "لا توجد طلبات شحن جواهر مطابقة.",
    "Loading recharge orders…": "جارٍ تحميل طلبات الشحن…",
    "History": "السجل",
    "Order / Player": "الطلب / اللاعب",
    "Admin action": "إجراء المشرف",
    "Order": "الطلب",
    "State": "الحالة",
    "Activity": "النشاط",
    "Actor / Note": "المنفّذ / الملاحظة",
    "No activity has been recorded for this order.": "لم يُسجل أي نشاط لهذا الطلب.",
    "No administrative accounts were found.": "لم يتم العثور على حسابات إدارية.",
    "No users matched this search.": "لا يوجد مستخدمون يطابقون هذا البحث.",
    "Account": "الحساب",
    "Roles": "الأدوار",
    "Effective permissions": "الصلاحيات الفعلية",
    "No roles": "لا توجد أدوار",
    "No permissions": "لا توجد صلاحيات",
    "Other balances": "أرصدة أخرى",
    "Ledger": "السجل",
    "Transactions": "المعاملات",
    "Operation": "العملية",
    "Balance": "الرصيد",
    "Reference": "المرجع",
    "Recent ledger entries": "أحدث قيود السجل",
    "Optional payment reference:": "مرجع الدفع (اختياري):",
    "Enter an administrative note (required):": "أدخل ملاحظة إدارية (مطلوبة):",
    "Approve and publish the server-calculated voting results?": "هل تريد اعتماد نتائج التصويت المحسوبة من الخادم ونشرها؟",
    "Cancel this event? This cannot be undone.": "هل تريد إلغاء هذه الفعالية؟ لا يمكن التراجع عن ذلك.",
    "Draft event created. Publish it when ready.": "تم إنشاء الفعالية كمسودة. انشرها عند الاستعداد.",
    "Event details saved.": "تم حفظ تفاصيل الفعالية.",
    "Event reward issued to the eligible participant.": "تم إصدار مكافأة الفعالية للمشارك المستحق.",
    "Suggestion accepted.": "تم قبول الاقتراح.",
    "Suggestion rejected.": "تم رفض الاقتراح.",
    "Event results approved and published.": "تم اعتماد نتائج الفعالية ونشرها.",
    "Diamond package status updated.": "تم تحديث حالة باقة الجواهر.",
    "Diamond order state updated.": "تم تحديث حالة طلب الجواهر.",
    "Inactive diamond package created.": "تم إنشاء باقة جواهر غير نشطة.",
    "Homepage reward settings saved.": "تم حفظ إعدادات مكافآت الصفحة الرئيسية.",
    "Package created. Review its server status before publishing.": "تم إنشاء الباقة. راجع حالتها على الخادم قبل نشرها.",
    "Payment method created. Review its server status before publishing.": "تم إنشاء وسيلة الدفع. راجع حالتها على الخادم قبل نشرها.",
    "Event details could not be saved.": "تعذر حفظ تفاصيل الفعالية.",
    "Event reward could not be issued.": "تعذر إصدار مكافأة الفعالية.",
    "The requested admin action failed.": "تعذر تنفيذ إجراء الإدارة المطلوب.",
    "Sign out failed.": "تعذر تسجيل الخروج.",
    "Order history could not be loaded.": "تعذر تحميل سجل الطلب.",
    "Wallet transaction history could not be loaded.": "تعذر تحميل سجل معاملات المحفظة.",
    "User directory could not be loaded.": "تعذر تحميل قائمة المستخدمين.",
    "Wallet records could not be loaded.": "تعذر تحميل سجلات المحافظ.",
    "Coin orders could not be loaded.": "تعذر تحميل طلبات العملات.",
    "Suggestions could not be loaded.": "تعذر تحميل الاقتراحات.",
    "Community events could not be loaded.": "تعذر تحميل فعاليات المجتمع.",
    "Diamond orders could not be loaded.": "تعذر تحميل طلبات الجواهر.",
    "Diamond packages could not be loaded.": "تعذر تحميل باقات الجواهر.",
    "The protected order service returned an error": "أعادت خدمة الطلبات المحمية خطأً",
    "The authorized audit service returned an error": "أعادت خدمة التدقيق المصرح بها خطأً",
    "The authorized community-events service returned an error": "أعادت خدمة فعاليات المجتمع المصرح بها خطأً",
    "The authorized suggestion service returned an error": "أعادت خدمة الاقتراحات المصرح بها خطأً",
    "The authorized recharge service returned an error": "أعادت خدمة الشحن المصرح بها خطأً",
    "No orders currently await review": "لا توجد طلبات بانتظار المراجعة حاليًا",
    "Pending payment review in the latest 100 server results": "طلبات بانتظار مراجعة الدفع ضمن أحدث 100 نتيجة من الخادم",
    "Pending submissions from the database": "اقتراحات معلّقة من قاعدة البيانات",
    "No payment reference": "لا يوجد مرجع دفع",
    "System": "النظام",
    "User": "مستخدم",
    "Loading the protected review queue…": "جارٍ تحميل قائمة المراجعة المحمية…",
    "There are no Coin orders awaiting review.": "لا توجد طلبات عملات بانتظار المراجعة.",
    "Loading server reward settings…": "جارٍ تحميل إعدادات المكافآت من الخادم…",
    "Inactive: no homepage reward can currently be claimed.": "غير نشطة: لا يمكن المطالبة بمكافأة الصفحة الرئيسية حاليًا.",
    "Loading events from the database…": "جارٍ تحميل الفعاليات من قاعدة البيانات…",
    "No community events have been created.": "لم يتم إنشاء فعاليات مجتمعية.",
    "No voting events or results are available.": "لا توجد فعاليات تصويت أو نتائج متاحة.",
    "There are no suggestions waiting for review.": "لا توجد اقتراحات بانتظار المراجعة.",
    "Loading diamond packages…": "جارٍ تحميل باقات الجواهر…",
    "No diamond packages are configured.": "لا توجد باقات جواهر مُعدة.",
    "No matching diamond recharge orders.": "لا توجد طلبات شحن جواهر مطابقة.",
    "Loading order history…": "جارٍ تحميل سجل الطلب…",
    "No activity has been recorded for this order.": "لم يُسجل أي نشاط لهذا الطلب.",
    "Loading administrative accounts…": "جارٍ تحميل الحسابات الإدارية…",
    "Searching account records…": "جارٍ البحث في سجلات الحسابات…",
    "No administrative accounts were found.": "لم يتم العثور على حسابات إدارية.",
    "No users matched this search.": "لا يوجد مستخدمون يطابقون هذا البحث.",
    "Loading wallet records…": "جارٍ تحميل سجلات المحافظ…",
    "No wallets matched this search.": "لا توجد محافظ تطابق هذا البحث.",
    "Loading wallet ledger…": "جارٍ تحميل سجل المحفظة…",
    "No Coins ledger entries are recorded for this account.": "لا توجد قيود عملات مسجلة لهذا الحساب.",
    "Draft event created. Publish it when ready.": "تم إنشاء الفعالية كمسودة. انشرها عند الاستعداد.",
    "Event details saved.": "تم حفظ تفاصيل الفعالية.",
    "Event reward issued to the eligible participant.": "تم إصدار مكافأة الفعالية للمشارك المستحق.",
    "Event results approved and published.": "تم اعتماد نتائج الفعالية ونشرها.",
    "Diamond package status updated.": "تم تحديث حالة باقة الجواهر.",
    "Diamond order state updated.": "تم تحديث حالة طلب الجواهر.",
    "Inactive diamond package created.": "تم إنشاء باقة جواهر غير نشطة.",
    "The order review failed.": "تعذرت مراجعة الطلب.",
    "The catalog update failed.": "تعذر تحديث الكتالوج.",
    "Catalog could not be loaded.": "تعذر تحميل الكتالوج.",
    "Reward settings could not be loaded.": "تعذر تحميل إعدادات المكافآت.",
    "Homepage reward settings could not be saved.": "تعذر حفظ إعدادات مكافآت الصفحة الرئيسية.",
    "Community event could not be created.": "تعذر إنشاء فعالية المجتمع.",
    "Suggestion review could not be saved.": "تعذر حفظ مراجعة الاقتراح.",
    "Diamond package could not be created.": "تعذر إنشاء باقة الجواهر.",
    "Loading game catalogs…": "جارٍ تحميل كتالوجات الألعاب…",
    "No audit entries are available.": "لا توجد سجلات تدقيق متاحة.",
    "The server returned an unreadable response. Please refresh and try again.": "أعاد الخادم استجابة غير قابلة للقراءة. حدّث الصفحة ثم حاول مجددًا.",
    "The server did not provide a CSRF token. Please sign in again.": "لم يوفّر الخادم رمز CSRF. سجّل الدخول مجددًا.",
    "Start payment review": "بدء مراجعة الدفع",
    "Reject payment": "رفض الدفع",
    "Mark paid": "تأكيد الدفع",
    "Mark payment failed": "تسجيل فشل الدفع",
    "Start delivery": "بدء التسليم",
    "Refund": "استرداد المبلغ",
    "Mark delivered": "تأكيد التسليم",
    "Mark delivery failed": "تسجيل فشل التسليم",
    "Review": "مراجعة",
    "Order / Player": "الطلب / اللاعب",
    "Admin action": "إجراء المشرف",
    "Activity": "النشاط",
    "State": "الحالة",
    "Actor / Note": "المنفّذ / الملاحظة",
    "Operation": "العملية",
    "Balance": "الرصيد",
    "Reference": "المرجع",
    "Other balances": "أرصدة أخرى",
    "Ledger": "السجل",
    "Transactions": "المعاملات",
    "Time (UTC)": "الوقت (UTC)",
    "Account": "الحساب",
    "Package": "الباقة",
    "Payment": "الدفع",
    "History": "السجل",
    "Edit event details": "تعديل تفاصيل الفعالية",
    "Save event changes": "حفظ تغييرات الفعالية",
    "Title": "العنوان",
    "Submitted by": "مقدّم من",
    "Participant user ID": "رقم حساب المشارك",
    "Award an eligible participant": "منح مكافأة لمشارك مستحق",
    "Earned Coins": "العملات المكتسبة",
    "Issue reward": "إصدار المكافأة",
    "Optional reviewer note": "ملاحظة اختيارية للمراجع",
    "Optional payment reference:": "مرجع الدفع (اختياري):",
    "Enter an administrative note (required):": "أدخل ملاحظة إدارية (مطلوبة):",
    "Approve and publish the server-calculated voting results?": "هل تريد اعتماد نتائج التصويت المحسوبة من الخادم ونشرها؟",
    "Cancel this event? This cannot be undone.": "هل تريد إلغاء هذه الفعالية؟ لا يمكن التراجع عن ذلك.",
    "draft": "مسودة",
    "published": "منشورة",
    "paused": "متوقفة مؤقتًا",
    "cancelled": "ملغاة",
    "results published": "نُشرت النتائج",
    "pending": "قيد الانتظار",
    "pending review": "بانتظار المراجعة",
    "payment verification": "التحقق من الدفع",
    "paid": "مدفوع",
    "processing": "قيد المعالجة",
    "delivered": "تم التسليم",
    "failed": "فشل",
    "rejected": "مرفوض",
    "refunded": "مسترد",
    "active": "نشط",
    "suspended": "موقوف",
    "deleted": "محذوف",
    "pending verification": "بانتظار التحقق",
    "earned": "مكتسبة",
    "purchased": "مشتراة",
  }));
  const languagePreferenceKey = "neen_control_room_lang";
  const originalTextNodes = new WeakMap();
  const originalElementTexts = new WeakMap();
  const originalAttributes = new WeakMap();
  let language = "en";
  const translate = (value) => {
    if (typeof value !== "string") return value;
    const exact = language === "ar" ? translations.get(value) ?? value : value;
    if (exact !== value) return exact;
    if (language === "ar") {
      const dynamic = [
        [/^(\d+) latest server records$/, "$1 أحدث سجلات الخادم"],
        [/^(\d+) currently published$/, "$1 منشورة حاليًا"],
        [/^(\d+) returned \(the API maximum is 100\)$/, "أُعيد $1 (الحد الأقصى لواجهة API هو 100)"],
        [/^Voting: (.+) — (.+)$/, "التصويت: $1 — $2"],
        [/^Submitted by (.+) \(user (.+)\) · (.+)$/, "قدّمه $1 (المستخدم $2) · $3"],
        [/^User ID: (.+) · (.+) · Created (.+)$/, "رقم المستخدم: $1 · $2 · تاريخ الإنشاء $3"],
        [/^User ID: (.+) · Updated (.+)$/, "رقم المستخدم: $1 · آخر تحديث $2"],
        [/^(\d+) total Coins$/, "$1 عملة إجمالًا"],
        [/^(\d+) Purchased · (\d+) Earned$/, "$1 مشتراة · $2 مكتسبة"],
        [/^(\d+) diamonds · (.+) (.+)$/, "$1 جوهرة · $2 $3"],
        [/^Payment: (.+) · Delivery: (.+)$/, "الدفع: $1 · التسليم: $2"],
        [/^Payment: (.+)$/, "الدفع: $1"],
        [/^(\d+) vote\(s\)$/, "$1 تصويت"],
        [/^(\d+) pairs$/, "$1 أزواج"],
        [/^(\d+) pieces$/, "$1 قطع"],
        [/^(\d+) free starts \/ day$/, "$1 محاولات مجانية يوميًا"],
        [/^(\d+) Coins \/ verified win$/, "$1 عملة لكل فوز موثّق"],
        [/^Active: (.+) Earned Coins every (.+) seconds\.$/, "نشطة: $1 عملة مكتسبة كل $2 ثانية."],
        [/^No (packages|payment methods) are configured\.$/, (_, type) => type === "packages" ? "لا توجد باقات مُعدة." : "لا توجد وسائل دفع مُعدة."],
        [/^Package updated\.$/, "تم تحديث الباقة."],
        [/^Payment method updated\.$/, "تم تحديث وسيلة الدفع."],
        [/^Package created\. Review its server status before publishing\.$/, "تم إنشاء الباقة. راجع حالتها على الخادم قبل نشرها."],
        [/^Payment method created\. Review its server status before publishing\.$/, "تم إنشاء وسيلة الدفع. راجع حالتها على الخادم قبل نشرها."],
        [/^package could not be created\.$/i, "تعذر إنشاء العنصر."],
        [/^Event (.+)\.$/, "الفعالية: $1."],
        [/^Order #(.+) was (.+)\.$/, "تم تحديث الطلب رقم $1 إلى الحالة: $2."],
        [/^Suggestion (accepted|rejected)\.$/, (_, decision) => `تم ${decision === "accepted" ? "قبول" : "رفض"} الاقتراح.`],
      ];
      for (const [pattern, replacement] of dynamic) {
        const match = value.match(pattern);
        if (match) {
          return typeof replacement === "function"
            ? replacement(...match)
            : value.replace(pattern, replacement);
        }
      }
    }
    return exact;
  };
  const setLocalizedText = (element, value) => {
    originalElementTexts.set(element, value);
    element.textContent = translate(value);
  };
  const localizeAttributes = (element) => {
    let attributes = originalAttributes.get(element);
    if (!attributes) {
      attributes = new Map();
      originalAttributes.set(element, attributes);
    }
    for (const attribute of ["placeholder", "aria-label", "title"]) {
      if (!attributes.has(attribute)) attributes.set(attribute, element.getAttribute(attribute));
      const value = attributes.get(attribute);
      if (value) element.setAttribute(attribute, translate(value));
    }
  };
  const localizeNode = (node) => {
    if (node instanceof Text) {
      if (!originalTextNodes.has(node)) originalTextNodes.set(node, node.data);
      const original = originalTextNodes.get(node);
      const trimmed = original.trim();
      const localized = translate(trimmed);
      if (localized !== trimmed) node.data = original.replace(trimmed, localized);
      else node.data = original;
      return;
    }
    if (!(node instanceof Element)) return;
    if (originalElementTexts.has(node)) {
      node.textContent = translate(originalElementTexts.get(node));
      return;
    }
    localizeAttributes(node);
    const walker = document.createTreeWalker(node, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
    for (let child = walker.nextNode(); child; child = walker.nextNode()) {
      if (child instanceof Text) localizeNode(child);
      else if (child instanceof Element && originalElementTexts.has(child)) {
        child.textContent = translate(originalElementTexts.get(child));
      } else if (child instanceof Element) {
        localizeAttributes(child);
      }
    }
  };
  const setLanguage = (nextLanguage, persist = true) => {
    language = nextLanguage === "ar" ? "ar" : "en";
    document.documentElement.lang = language;
    document.documentElement.dir = language === "ar" ? "rtl" : "ltr";
    document.title = language === "ar" ? translations.get("NEEN Admin Dashboard") : "NEEN Admin Dashboard";
    const toggle = document.querySelector("[data-language-toggle]");
    if (toggle instanceof HTMLButtonElement) {
      toggle.textContent = language === "ar" ? "EN" : "عربي";
      toggle.setAttribute("aria-label", "Switch dashboard language");
    }
    if (persist) {
      try {
        window.localStorage.setItem(languagePreferenceKey, language);
      } catch (error) {
        console.warn("Admin dashboard language preference could not be saved.", error);
      }
    }
    localizeNode(app);
    const toast = document.querySelector("[data-toast]");
    if (toast instanceof HTMLElement) localizeNode(toast);
    if (toggle instanceof HTMLButtonElement) {
      toggle.textContent = language === "ar" ? "EN" : "عربي";
    }
  };
  const languageToggle = document.querySelector("[data-language-toggle]");
  if (languageToggle instanceof HTMLButtonElement) {
    languageToggle.addEventListener("click", () => {
      setLanguage(language === "en" ? "ar" : "en");
    });
  }
  const languageObserver = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      mutation.addedNodes.forEach(localizeNode);
    }
  });
  languageObserver.observe(app, { childList: true, subtree: true });
  try {
    const savedLanguage = window.localStorage.getItem(languagePreferenceKey)
      ?? window.localStorage.getItem("neen_owner_lang");
    setLanguage(savedLanguage === "ar" ? "ar" : "en", false);
  } catch (error) {
    console.warn("Admin dashboard language preference could not be read.", error);
    setLanguage("en", false);
  }

  const permissions = new Set((app.dataset.permissions ?? "").split(/\s+/).filter(Boolean));
  const panels = [...document.querySelectorAll("[data-panel]")];
  const navItems = [...document.querySelectorAll("[data-section]")];
  const toast = document.querySelector("[data-toast]");
  let toastTimer;
  const loaded = new Set();

  const make = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };

  const showState = (container, message, isError = false) => {
    container.replaceChildren(make("p", `admin-state${isError ? " is-error" : ""}`, message));
  };

  const notify = (message, isError = false) => {
    if (!(toast instanceof HTMLElement)) return;
    window.clearTimeout(toastTimer);
    setLocalizedText(toast, message);
    toast.classList.toggle("is-error", isError);
    toast.classList.add("is-visible");
    toastTimer = window.setTimeout(() => toast.classList.remove("is-visible"), 4200);
  };

  const apiRequest = async (path, options = {}) => {
    const response = await fetch(path, {
      ...options,
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: "application/json",
        ...(options.body ? { "Content-Type": "application/json" } : {}),
        ...(options.headers ?? {}),
      },
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("The server returned an unreadable response. Please refresh and try again.");
    }
    if (!response.ok || result.success !== true) {
      const message = result.errors?.[0]?.message;
      throw new Error(typeof message === "string" ? message : `Request failed (${response.status}).`);
    }
    return result.data;
  };

  const postAdminMutation = async (path, method, payload) => {
    const csrf = await apiRequest("/api/auth/csrf");
    if (typeof csrf.csrf_token !== "string") {
      throw new Error("The server did not provide a CSRF token. Please sign in again.");
    }
    return apiRequest(path, {
      method,
      headers: { "X-CSRF-Token": csrf.csrf_token },
      body: JSON.stringify(payload),
    });
  };

  const createIdempotencyKey = () => {
    if (typeof window.crypto?.randomUUID === "function") return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (typeof window.crypto?.getRandomValues === "function") window.crypto.getRandomValues(bytes);
    else for (let index = 0; index < bytes.length; index += 1) bytes[index] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, "0"));
    return `${hex.slice(0, 4).join("")}-${hex.slice(4, 6).join("")}-${hex.slice(6, 8).join("")}-${hex.slice(8, 10).join("")}-${hex.slice(10).join("")}`;
  };

  const setCount = (selector, value, statusSelector, status) => {
    const count = document.querySelector(selector);
    const note = document.querySelector(statusSelector);
    if (count) count.textContent = String(value);
    if (note) setLocalizedText(note, status);
  };

  const formatDate = (value) => {
    if (typeof value !== "string" || value === "") return "—";
    return value.replace("T", " ").replace(/\.\d+Z$/, " UTC");
  };

  const buildTable = (headers, rows) => {
    const wrapper = make("div", "admin-table-wrap");
    const table = make("table", "admin-table");
    const head = make("thead");
    const headRow = make("tr");
    for (const label of headers) headRow.append(make("th", "", label));
    head.append(headRow);
    const body = make("tbody");
    for (const row of rows) body.append(row);
    table.append(head, body);
    wrapper.append(table);
    return wrapper;
  };

  const renderAudit = (entries, container, limit = 50) => {
    if (!Array.isArray(entries) || entries.length === 0) {
      showState(container, "No audit entries are available.");
      return;
    }
    const rows = entries.slice(0, limit).map((entry) => {
      const row = make("tr");
      const actor = make("td");
      actor.append(make("strong", "", entry.actor_username || "System"));
      actor.append(make("small", "", `User ID: ${entry.actor_user_id ?? "—"}`));
      const action = make("td");
      action.append(make("strong", "", entry.action_type));
      action.append(make("small", "", `${entry.entity_type}${entry.entity_id ? ` #${entry.entity_id}` : ""}`));
      const when = make("td", "", formatDate(entry.created_at));
      row.append(actor, action, when);
      return row;
    });
    container.replaceChildren(buildTable(["Actor", "Action", "Time (UTC)"], rows));
  };

  const loadAudit = async (force = false) => {
    const container = document.querySelector("[data-audit-list]");
    if (!(container instanceof HTMLElement) || !permissions.has("view_audit_logs")) return;
    if (loaded.has("audit") && !force) return;
    showState(container, "Loading authorized audit entries…");
    try {
      const { entries } = await apiRequest("/api/admin/audit-logs");
      renderAudit(entries, container);
      const count = Array.isArray(entries) ? entries.length : 0;
      setCount("[data-dashboard-audit]", count, "[data-dashboard-audit-status]", `${count} latest server records`);
      const dashboardActivity = document.querySelector("[data-dashboard-activity]");
      if (dashboardActivity instanceof HTMLElement) renderAudit(entries, dashboardActivity, 5);
      loaded.add("audit");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Audit log could not be loaded.";
      showState(container, message, true);
      const dashboardActivity = document.querySelector("[data-dashboard-activity]");
      if (dashboardActivity instanceof HTMLElement) showState(dashboardActivity, message, true);
      setCount("[data-dashboard-audit]", "!", "[data-dashboard-audit-status]", "The authorized audit service returned an error");
    }
  };

  const loadGames = async (force = false) => {
    const container = document.querySelector("[data-game-list]");
    if (!(container instanceof HTMLElement) || (loaded.has("games") && !force)) return;
    showState(container, "Loading game catalogs…");
    try {
      const [memory, jigsaw] = await Promise.all([
        apiRequest("/api/games/memory-match"),
        apiRequest("/api/games/picture-jigsaw"),
      ]);
      const games = [memory.game, jigsaw.game];
      container.replaceChildren();
      for (const game of games) {
        const card = make("article", "admin-game-card");
        card.append(make("p", "admin-eyebrow", "SERVER CATALOG"));
        card.append(make("h3", "", game.name));
        card.append(make("p", "", game.description ?? "NEEN original game"));
        const facts = make("div", "admin-game-facts");
        facts.append(make("span", "", "Catalog available"));
        facts.append(make("span", "", `${game.free_attempts_per_day} free starts / day`));
        facts.append(make("span", "", `${game.reset_timezone} reset`));
        if (game.reward_amount_coins !== undefined) {
          facts.append(make("span", "", `${game.reward_amount_coins} Coins / verified win`));
        }
        if (game.pairs_total !== undefined) facts.append(make("span", "", `${game.pairs_total} pairs`));
        if (game.piece_count !== undefined) facts.append(make("span", "", `${game.piece_count} pieces`));
        card.append(facts);
        container.append(card);
      }
      setCount("[data-dashboard-games]", games.length, "[data-dashboard-games-status]", "Read from the public game catalogs");
      loaded.add("games");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Game catalogs could not be loaded.";
      showState(container, message, true);
      setCount("[data-dashboard-games]", "!", "[data-dashboard-games-status]", "The game catalog service returned an error");
    }
  };

  const orderReviewForm = (order) => {
    const form = make("form", "admin-review-form");
    form.dataset.orderId = String(order.id);
    const reason = document.createElement("textarea");
    reason.name = "reason";
    reason.required = true;
    reason.minLength = 3;
    reason.maxLength = 1000;
    reason.placeholder = "Required review reason";
    reason.setAttribute("aria-label", `Review reason for order ${order.id}`);
    const actions = make("div", "admin-review-actions");
    const approve = make("button", "admin-primary-button", "Approve");
    approve.type = "submit";
    approve.name = "decision";
    approve.value = "approved";
    const reject = make("button", "admin-danger-button", "Reject");
    reject.type = "submit";
    reject.name = "decision";
    reject.value = "rejected";
    actions.append(approve, reject);
    form.append(reason, actions);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submitter = event.submitter;
      if (!(submitter instanceof HTMLButtonElement)) return;
      const decision = submitter.value;
      if (
        decision === "approved"
        && !window.confirm(translate("Approve this order? The server will credit its configured Purchased Coins amount."))
      ) return;
      const buttons = [...form.querySelectorAll("button")];
      buttons.forEach((button) => { button.disabled = true; });
      try {
        await postAdminMutation(`/api/admin/coin-orders/${encodeURIComponent(order.id)}/review`, "POST", {
          decision,
          reason: reason.value,
          idempotency_key: createIdempotencyKey(),
        });
        notify(`Order #${order.id} was ${decision}.`);
        loaded.delete("orders");
        await loadOrders(true);
      } catch (error) {
        notify(error instanceof Error ? error.message : "The order review failed.", true);
        buttons.forEach((button) => { button.disabled = false; });
      }
    });
    return form;
  };

  const loadOrders = async (force = false) => {
    const container = document.querySelector("[data-order-list]");
    if (!(container instanceof HTMLElement) || !permissions.has("review_coin_orders")) return;
    if (loaded.has("orders") && !force) return;
    showState(container, "Loading the protected review queue…");
    try {
      const { orders } = await apiRequest("/api/admin/coin-orders?status=pending_review");
      if (!Array.isArray(orders) || orders.length === 0) {
        showState(container, "There are no Coin orders awaiting review.");
        setCount("[data-dashboard-orders]", 0, "[data-dashboard-orders-status]", "No orders currently await review");
      } else {
        const rows = orders.map((order) => {
          const row = make("tr");
          const orderCell = make("td");
          orderCell.append(make("strong", "", `#${order.id}`));
          orderCell.append(make("small", "", formatDate(order.created_at)));
          const player = make("td");
          player.append(make("strong", "", order.username));
          player.append(make("small", "", order.email));
          const packageCell = make("td");
          packageCell.append(make("strong", "", order.package_name));
          packageCell.append(make("small", "", `${order.coin_amount} Coins · ${order.price} ${order.currency_code}`));
          const payment = make("td");
          payment.append(make("strong", "", order.payment_method_name));
          payment.append(make("small", "admin-order-proof", order.payment_reference || "No payment reference"));
          if (order.proof_details) payment.append(make("small", "admin-order-proof", order.proof_details));
          const evidenceCell = make("td", "", formatDate(order.evidence_submitted_at));
          const reviewCell = make("td");
          reviewCell.append(orderReviewForm(order));
          row.append(orderCell, player, packageCell, payment, evidenceCell, reviewCell);
          return row;
        });
        container.replaceChildren(buildTable(["Order", "Account", "Package", "Payment evidence", "Submitted", "Review"], rows));
        setCount(
          "[data-dashboard-orders]",
          orders.length,
          "[data-dashboard-orders-status]",
          `${orders.length} returned (the API maximum is 100)`,
        );
      }
      loaded.add("orders");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Coin orders could not be loaded.";
      showState(container, message, true);
      setCount("[data-dashboard-orders]", "!", "[data-dashboard-orders-status]", "The protected order service returned an error");
    }
  };

  const renderCatalog = (items, container, type) => {
    if (!Array.isArray(items) || items.length === 0) {
      showState(container, `No ${type} are configured.`);
      return;
    }
    const rows = items.map((item) => {
      const row = make("tr");
      const name = make("td");
      name.append(make("strong", "", type === "packages" ? item.name : item.display_name));
      name.append(make("small", "", type === "packages" ? `${item.coin_amount} Coins · ${item.currency_code}` : item.code));
      const terms = make("td");
      if (type === "packages") {
        terms.textContent = `${item.price} ${item.currency_code}`;
      } else {
        terms.textContent = item.proof_required ? "Proof required" : "Proof optional";
        if (item.instructions) terms.append(make("small", "", item.instructions));
      }
      const status = make("td");
      status.append(make("span", "admin-status-pill", item.is_active ? "Active" : "Inactive"));
      const action = make("td");
      const toggle = make("button", "admin-secondary-button", item.is_active ? "Deactivate" : "Activate");
      toggle.type = "button";
      toggle.addEventListener("click", async () => {
        toggle.disabled = true;
        try {
          const resource = type === "packages" ? "coin-packages" : "coin-payment-methods";
          await postAdminMutation(`/api/admin/${resource}/${encodeURIComponent(item.id)}`, "PATCH", {
            is_active: !item.is_active,
          });
          notify(`${type === "packages" ? "Package" : "Payment method"} updated.`);
          loaded.delete(type);
          await loadCatalog(type, true);
        } catch (error) {
          notify(error instanceof Error ? error.message : "The catalog update failed.", true);
          toggle.disabled = false;
        }
      });
      action.append(toggle);
      row.append(name, terms, status, action);
      return row;
    });
    container.replaceChildren(buildTable(["Name", "Terms", "Status", "Action"], rows));
  };

  const loadCatalog = async (type, force = false) => {
    const permission = type === "packages" ? "manage_coin_packages" : "manage_coin_payment_methods";
    const container = document.querySelector(type === "packages" ? "[data-package-list]" : "[data-method-list]");
    if (!(container instanceof HTMLElement) || !permissions.has(permission)) return;
    if (loaded.has(type) && !force) return;
    showState(container, "Loading server catalog…");
    try {
      const resource = type === "packages" ? "coin-packages" : "coin-payment-methods";
      const data = await apiRequest(`/api/admin/${resource}`);
      const items = type === "packages" ? data.packages : data.payment_methods;
      renderCatalog(items, container, type);
      loaded.add(type);
    } catch (error) {
      showState(container, error instanceof Error ? error.message : "Catalog could not be loaded.", true);
    }
  };

  const bindCatalogForm = (selector, type, resource, fields) => {
    const form = document.querySelector(selector);
    if (!(form instanceof HTMLFormElement)) return;
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submit = form.querySelector('button[type="submit"]');
      if (submit instanceof HTMLButtonElement) submit.disabled = true;
      const values = new FormData(form);
      const payload = {};
      for (const field of fields) {
        if (field.type === "boolean") {
          payload[field.name] = values.has(field.name);
        } else {
          payload[field.name] = String(values.get(field.name) ?? "").trim();
        }
      }
      try {
        await postAdminMutation(`/api/admin/${resource}`, "POST", payload);
        form.reset();
        notify(`${type} created. Review its server status before publishing.`);
        loaded.delete(type === "package" ? "packages" : "methods");
        await loadCatalog(type === "package" ? "packages" : "methods", true);
      } catch (error) {
        notify(error instanceof Error ? error.message : `${type} could not be created.`, true);
      } finally {
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
      }
    });
  };

  bindCatalogForm("[data-package-form]", "package", "coin-packages", [
    { name: "name" },
    { name: "coin_amount" },
    { name: "price" },
    { name: "currency_code" },
    { name: "is_active", type: "boolean" },
  ]);
  bindCatalogForm("[data-method-form]", "payment method", "coin-payment-methods", [
    { name: "code" },
    { name: "display_name" },
    { name: "instructions" },
    { name: "proof_required", type: "boolean" },
    { name: "is_active", type: "boolean" },
  ]);

  const loadHomepageRewardConfig = async (force = false) => {
    const container = document.querySelector("[data-homepage-reward-config]");
    const form = document.querySelector("[data-homepage-reward-form]");
    if (
      !(container instanceof HTMLElement)
      || !(form instanceof HTMLFormElement)
      || !permissions.has("manage_homepage_rewards")
      || (loaded.has("homepage-rewards") && !force)
    ) return;
    showState(container, "Loading server reward settings…");
    try {
      const { configuration } = await apiRequest("/api/admin/homepage-rewards");
      form.elements.namedItem("reward_amount").value = configuration.reward_amount ?? "";
      form.elements.namedItem("window_seconds").value = configuration.window_seconds ?? "";
      form.elements.namedItem("is_active").checked = configuration.is_active;
      showState(
        container,
        configuration.is_active
          ? `Active: ${configuration.reward_amount} Earned Coins every ${configuration.window_seconds} seconds.`
          : "Inactive: no homepage reward can currently be claimed.",
      );
      loaded.add("homepage-rewards");
    } catch (error) {
      showState(container, error instanceof Error ? error.message : "Reward settings could not be loaded.", true);
    }
  };

  const homepageRewardForm = document.querySelector("[data-homepage-reward-form]");
  if (homepageRewardForm instanceof HTMLFormElement) {
    homepageRewardForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submit = homepageRewardForm.querySelector('button[type="submit"]');
      if (submit instanceof HTMLButtonElement) submit.disabled = true;
      const formValues = new FormData(homepageRewardForm);
      try {
        await postAdminMutation("/api/admin/homepage-rewards", "PATCH", {
          reward_amount: String(formValues.get("reward_amount") ?? ""),
          window_seconds: String(formValues.get("window_seconds") ?? ""),
          is_active: formValues.has("is_active"),
        });
        notify("Homepage reward settings saved.");
        loaded.delete("homepage-rewards");
        await loadHomepageRewardConfig(true);
      } catch (error) {
        notify(error instanceof Error ? error.message : "Homepage reward settings could not be saved.", true);
      } finally {
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
      }
    });
  }

  const renderEventCard = (event, includeRewardForm = false) => {
    const card = make("article", "admin-module-card");
    const heading = make("div", "admin-module-heading");
    heading.append(make("h3", "", event.title));
    heading.append(make("span", "admin-status-pill", String(event.status).replaceAll("_", " ")));
    card.append(heading);
    card.append(make("p", "admin-module-description", event.description));
    const facts = make("div", "admin-module-facts");
    facts.append(make("span", "", `Voting: ${formatDate(event.starts_at)} — ${formatDate(event.ends_at)}`));
    facts.append(make("span", "", `${event.total_votes ?? 0} vote(s)`));
    card.append(facts);
    const options = Array.isArray(event.options) ? event.options : [];
    if (options.length) {
      const optionList = make("ul", "admin-event-options");
      for (const option of options) {
        const votes = option.vote_count === undefined ? "" : ` — ${option.vote_count} vote(s)`;
        optionList.append(make("li", "", `${option.label}${votes}`));
      }
      card.append(optionList);
    }

    const actions = make("div", "admin-module-actions");
    if (event.status === "draft" || event.status === "paused") {
      actions.append(makeAdminButton("Publish voting", "publish", event.id));
    } else if (event.status === "published") {
      actions.append(makeAdminButton("Pause voting", "paused", event.id));
      actions.append(makeAdminButton("Approve results", "approve-results", event.id, true));
    }
    if (event.status !== "cancelled" && event.status !== "results_published") {
      actions.append(makeAdminButton("Cancel event", "cancelled", event.id, true));
    }
    actions.append(makeAdminButton("Activity log", "event-activity", event.id));
    card.append(actions);

    const edit = document.createElement("details");
    edit.hidden = event.status === "results_published";
    const editSummary = make("summary", "", "Edit event details");
    edit.append(editSummary);
    const editForm = make("form", "admin-form-grid");
    editForm.dataset.eventEditForm = "";
    editForm.dataset.eventId = String(event.id);
    const localDateTime = (value) => {
      const date = new Date(value);
      return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
    };
    const appendEditField = (labelText, name, value, wide = false, multiline = false) => {
      const label = make("label", wide ? "admin-form-wide" : "", labelText);
      const input = multiline ? document.createElement("textarea") : document.createElement("input");
      input.name = name;
      input.value = value;
      input.required = true;
      if (multiline) {
        input.rows = 3;
        input.maxLength = name === "description" ? 4000 : 1500;
      } else if (name === "starts_at" || name === "ends_at") {
        input.type = "datetime-local";
      } else {
        input.maxLength = 150;
      }
      label.append(input);
      editForm.append(label);
    };
    appendEditField("Title", "title", event.title);
    appendEditField("Starts at (local time)", "starts_at", localDateTime(event.starts_at));
    appendEditField("Ends at (local time)", "ends_at", localDateTime(event.ends_at));
    appendEditField("Description", "description", event.description, true, true);
    if ((event.total_votes ?? 0) === 0) {
      appendEditField("Poll options (one per line)", "options", options.map((option) => option.label).join("\n"), true, true);
    }
    const saveEdit = make("button", "admin-primary-button", "Save event changes");
    saveEdit.type = "submit";
    editForm.append(saveEdit);
    edit.append(editForm);
    card.append(edit);

    if (includeRewardForm && permissions.has("manage_event_rewards") && event.status === "results_published") {
      const form = make("form", "admin-event-reward-form");
      form.dataset.eventId = String(event.id);
      form.append(make("h4", "", "Award an eligible participant"));
      for (const [label, name, type, min] of [
        ["Participant user ID", "user_id", "number", "1"],
        ["Earned Coins", "amount", "number", "1"],
      ]) {
        const field = make("label", "", label);
        const input = document.createElement("input");
        input.name = name;
        input.type = type;
        input.min = min;
        input.step = "1";
        input.required = true;
        field.append(input);
        form.append(field);
      }
      const reason = make("label", "", "Reason");
      const reasonInput = document.createElement("input");
      reasonInput.name = "reason";
      reasonInput.minLength = 3;
      reasonInput.maxLength = 1000;
      reasonInput.required = true;
      reason.append(reasonInput);
      form.append(reason, make("button", "admin-primary-button", "Issue reward"));
      const submit = form.querySelector("button");
      submit.type = "submit";
      card.append(form);
    }
    return card;
  };

  const makeAdminButton = (label, action, id, danger = false) => {
    const button = make("button", danger ? "admin-danger-button" : "admin-secondary-button", label);
    button.type = "button";
    button.dataset.adminAction = action;
    button.dataset.id = String(id);
    return button;
  };

  const loadCommunityEvents = async (force = false) => {
    const containers = [
      document.querySelector("[data-community-events-list]"),
      document.querySelector("[data-event-results-list]"),
    ].filter((container) => container instanceof HTMLElement);
    if (!containers.length || !permissions.has("manage_community_events")) return;
    if (loaded.has("community-events") && !force) return;
    containers.forEach((container) => showState(container, "Loading events from the database…"));
    try {
      const { events } = await apiRequest("/api/admin/events");
      const items = Array.isArray(events) ? events : [];
      await Promise.all(items.map(async (event) => {
        if (event.status !== "results_published") return;
        const { event: details } = await apiRequest(`/api/events/${event.id}`);
        event.options = details.options;
        event.total_votes = details.total_votes;
      }));
      const eventContainer = containers.find((container) => container.matches("[data-community-events-list]"));
      if (eventContainer) {
        if (!items.length) showState(eventContainer, "No community events have been created.");
        else eventContainer.replaceChildren(...items.map((event) => renderEventCard(event, true)));
      }
      const resultsContainer = containers.find((container) => container.matches("[data-event-results-list]"));
      if (resultsContainer) {
        if (!items.length) showState(resultsContainer, "No voting events or results are available.");
        else resultsContainer.replaceChildren(...items.map((event) => renderEventCard(event)));
      }
      const published = items.filter((event) => event.status === "published").length;
      setCount("[data-dashboard-events]", items.length, "[data-dashboard-events-status]", `${published} currently published`);
      loaded.add("community-events");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Community events could not be loaded.";
      containers.forEach((container) => showState(container, message, true));
      setCount("[data-dashboard-events]", "!", "[data-dashboard-events-status]", "The authorized community-events service returned an error");
    }
  };

  const loadEventSuggestions = async (force = false) => {
    const container = document.querySelector("[data-event-suggestions-list]");
    if (!(container instanceof HTMLElement) || !permissions.has("review_event_suggestions")) return;
    if (loaded.has("event-suggestions") && !force) return;
    showState(container, "Loading pending suggestions…");
    try {
      const { suggestions } = await apiRequest("/api/admin/event-suggestions");
      const items = Array.isArray(suggestions) ? suggestions : [];
      if (!items.length) showState(container, "There are no suggestions waiting for review.");
      else {
        container.replaceChildren(...items.map((suggestion) => {
          const card = make("article", "admin-module-card");
          const heading = make("div", "admin-module-heading");
          heading.append(make("h3", "", suggestion.title));
          heading.append(make("span", "admin-status-pill", suggestion.status));
          card.append(heading);
          card.append(make("p", "admin-module-description", suggestion.description));
          card.append(make("p", "admin-module-meta", `Submitted by ${suggestion.username} (user ${suggestion.user_id}) · ${formatDate(suggestion.created_at)}`));
          const form = make("form", "admin-suggestion-review");
          form.dataset.suggestionId = String(suggestion.id);
          const note = document.createElement("textarea");
          note.name = "note";
          note.maxLength = 1000;
          note.placeholder = "Optional reviewer note";
          form.append(note);
          for (const [label, decision, danger] of [["Accept", "accepted", false], ["Reject", "rejected", true]]) {
            const button = make("button", danger ? "admin-danger-button" : "admin-primary-button", label);
            button.type = "submit";
            button.value = decision;
            button.name = "decision";
            form.append(button);
          }
          card.append(form);
          return card;
        }));
      }
      setCount("[data-dashboard-suggestions]", items.length, "[data-dashboard-suggestions-status]", "Pending submissions from the database");
      loaded.add("event-suggestions");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Suggestions could not be loaded.";
      showState(container, message, true);
      setCount("[data-dashboard-suggestions]", "!", "[data-dashboard-suggestions-status]", "The authorized suggestion service returned an error");
    }
  };

  const loadDiamondPackages = async (force = false) => {
    const container = document.querySelector("[data-diamond-package-list]");
    if (!(container instanceof HTMLElement) || !permissions.has("manage_diamond_packages")) return;
    if (loaded.has("diamond-packages") && !force) return;
    showState(container, "Loading diamond packages…");
    try {
      const { packages } = await apiRequest("/api/admin/diamonds/packages");
      const items = Array.isArray(packages) ? packages : [];
      if (!items.length) showState(container, "No diamond packages are configured.");
      else {
        const rows = items.map((item) => {
          const row = make("tr");
          const name = make("td");
          name.append(make("strong", "", item.name));
          name.append(make("small", "", `ID: ${item.id}`));
          const terms = make("td", "", `${item.diamond_amount} diamonds · ${item.price} ${item.currency_code}`);
          const status = make("td", "", item.is_active ? "Active" : "Inactive");
          const action = make("td");
          const button = makeAdminButton(item.is_active ? "Deactivate" : "Activate", "diamond-package-toggle", item.id, item.is_active);
          button.dataset.active = String(!item.is_active);
          action.append(button);
          row.append(name, terms, status, action);
          return row;
        });
        container.replaceChildren(buildTable(["Package", "Terms", "Status", "Action"], rows));
      }
      loaded.add("diamond-packages");
    } catch (error) {
      showState(container, error instanceof Error ? error.message : "Diamond packages could not be loaded.", true);
    }
  };

  const loadDiamondOrders = async (search = "", force = false) => {
    const container = document.querySelector("[data-diamond-admin-orders]");
    if (!(container instanceof HTMLElement) || !permissions.has("manage_diamond_orders")) return;
    if (loaded.has("diamond-orders") && !force && search === "") return;
    showState(container, "Loading recharge orders…");
    try {
      const query = search.trim() ? `?q=${encodeURIComponent(search.trim())}` : "";
      const { orders } = await apiRequest(`/api/admin/diamonds/orders${query}`);
      const items = Array.isArray(orders) ? orders : [];
      if (!items.length) showState(container, "No matching diamond recharge orders.");
      else {
        const rows = items.map((order) => {
          const row = make("tr");
          const identity = make("td");
          identity.append(make("strong", "", order.order_number));
          identity.append(make("small", "", `${order.username} · Player ID ${order.player_id}`));
          identity.append(make("small", "admin-order-proof", order.payment_reference || "No payment reference"));
          identity.append(make("small", "", `User ${order.user_id} · ${formatDate(order.created_at)}`));
          const packageInfo = make("td", "", `${order.package_name} · ${order.diamond_amount} diamonds · ${order.price} ${order.currency_code}`);
          const state = make("td");
          state.append(make("strong", "", String(order.order_status).replaceAll("_", " ")));
          state.append(make("small", "", `Payment: ${order.payment_status} · Delivery: ${order.delivery_status}`));
          const action = make("td");
          const actions = diamondOrderActions(order.order_status, order.payment_status, order.delivery_status);
          const actionWrap = make("div", "admin-order-action-list");
          actions.forEach(([label, name]) => {
            const actionButton = makeAdminButton(
              label,
              "diamond-order-action",
              order.id,
              ["reject_payment", "refund", "fail_payment", "fail_delivery"].includes(name),
            );
            actionButton.dataset.transition = name;
            actionWrap.append(actionButton);
          });
          actionWrap.append(makeAdminButton("History", "diamond-order-history", order.id));
          action.append(actionWrap);
          row.append(identity, packageInfo, state, action);
          return row;
        });
        container.replaceChildren(buildTable(["Order / Player", "Package", "State", "Admin action"], rows));
      }
      if (search.trim() === "") {
        const pending = items.filter((order) => ["pending", "payment_verification"].includes(order.order_status)).length;
        setCount("[data-dashboard-diamonds]", pending, "[data-dashboard-diamonds-status]", "Pending payment review in the latest 100 server results");
      }
      loaded.add("diamond-orders");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Diamond orders could not be loaded.";
      showState(container, message, true);
      setCount("[data-dashboard-diamonds]", "!", "[data-dashboard-diamonds-status]", "The authorized recharge service returned an error");
    }
  };

  const diamondOrderActions = (status, paymentStatus, deliveryStatus) => {
    if (status === "pending") return [["Start payment review", "begin_verification"], ["Reject payment", "reject_payment"]];
    if (status === "payment_verification") {
      return [["Mark paid", "mark_paid"], ["Reject payment", "reject_payment"], ["Mark payment failed", "fail_payment"]];
    }
    if (status === "paid") return [["Start delivery", "begin_delivery"], ["Refund", "refund"]];
    if (status === "processing") return [["Mark delivered", "mark_delivered"], ["Mark delivery failed", "fail_delivery"]];
    if (status === "failed" && paymentStatus === "paid" && deliveryStatus !== "delivered") return [["Refund", "refund"]];
    return [];
  };

  const loadDiamondOrderHistory = async (id) => {
    const container = document.querySelector("[data-diamond-order-history]");
    if (!(container instanceof HTMLElement)) return;
    showState(container, "Loading order history…");
    try {
      const { order } = await apiRequest(`/api/admin/diamonds/orders/${id}`);
      const rows = (order.activity ?? []).map((entry) => {
        const row = make("tr");
        row.append(make("td", "", `${entry.activity_type} (${entry.from_order_status ?? "—"} → ${entry.to_order_status ?? "—"})`));
        row.append(make("td", "", `Payment: ${entry.payment_status} · Delivery: ${entry.delivery_status}`));
        const actor = make("td");
        actor.append(make("strong", "", entry.actor_username ?? "User"));
        actor.append(make("small", "", entry.admin_note ?? "No note"));
        row.append(actor, make("td", "", formatDate(entry.created_at)));
        return row;
      });
      if (!rows.length) showState(container, "No activity has been recorded for this order.");
      else {
        container.replaceChildren(make("h3", "", `Order ${order.order_number} history`), buildTable(["Activity", "State", "Actor / Note", "Time (UTC)"], rows));
      }
    } catch (error) {
      showState(container, error instanceof Error ? error.message : "Order history could not be loaded.", true);
    }
  };

  const loadAdminUsers = async (search = "", adminsOnly = false, force = false) => {
    const container = document.querySelector(adminsOnly ? "[data-admin-accounts]" : "[data-admin-users]");
    if (!(container instanceof HTMLElement) || !permissions.has("view_admin_users")) return;
    const key = adminsOnly ? "admin-accounts" : "admin-users";
    if (loaded.has(key) && !force && search === "") return;
    showState(container, adminsOnly ? "Loading administrative accounts…" : "Searching account records…");
    try {
      const params = new URLSearchParams();
      if (search.trim()) params.set("q", search.trim());
      if (adminsOnly) params.set("admins_only", "1");
      const query = params.toString();
      const suffix = query ? `?${query}` : "";
      const { users } = await apiRequest(`/api/admin/users${suffix}`);
      const items = Array.isArray(users) ? users : [];
      if (!items.length) showState(container, adminsOnly ? "No administrative accounts were found." : "No users matched this search.");
      else {
        const rows = items.map((user) => {
          const row = make("tr");
          const account = make("td");
          account.append(make("strong", "", user.username));
          account.append(make("small", "", `User ID: ${user.id} · ${user.status} · Created ${formatDate(user.created_at)}`));
          const roles = make("td");
          roles.append(make("strong", "", user.roles.length ? user.roles.join(", ") : "No roles"));
          const permissionList = make("td", "", user.permissions.length ? user.permissions.join(", ") : "No permissions");
          row.append(account, roles, permissionList);
          return row;
        });
        container.replaceChildren(buildTable(["Account", "Roles", "Effective permissions"], rows));
      }
      loaded.add(key);
    } catch (error) {
      showState(container, error instanceof Error ? error.message : "User directory could not be loaded.", true);
    }
  };

  const loadAdminWallets = async (search = "", force = false) => {
    const container = document.querySelector("[data-admin-wallets]");
    if (!(container instanceof HTMLElement) || !permissions.has("view_all_wallets")) return;
    if (loaded.has("admin-wallets") && !force && search.trim() === "") return;
    showState(container, "Loading wallet records…");
    try {
      const query = search.trim() ? `?q=${encodeURIComponent(search.trim())}` : "";
      const { wallets } = await apiRequest(`/api/admin/wallets${query}`);
      const items = Array.isArray(wallets) ? wallets : [];
      if (!items.length) showState(container, "No wallets matched this search.");
      else {
        const rows = items.map((wallet) => {
          const row = make("tr");
          const account = make("td");
          account.append(make("strong", "", wallet.username));
          account.append(make("small", "", `User ID: ${wallet.user_id} · Updated ${formatDate(wallet.updated_at)}`));
          const coins = make("td");
          coins.append(make("strong", "", `${wallet.coin_balance} total Coins`));
          coins.append(make("small", "", `${wallet.purchased_coin_balance} Purchased · ${wallet.earned_coin_balance} Earned`));
          const otherBalance = make("td", "", `${wallet.diamond_balance} Diamonds · ${wallet.currency_code}`);
          const action = make("td");
          action.append(makeAdminButton("Transactions", "wallet-transactions", wallet.user_id));
          row.append(account, coins, otherBalance, action);
          return row;
        });
        container.replaceChildren(buildTable(["Account", "Coins", "Other balances", "Ledger"], rows));
      }
      loaded.add("admin-wallets");
    } catch (error) {
      showState(container, error instanceof Error ? error.message : "Wallet records could not be loaded.", true);
    }
  };

  const loadWalletTransactions = async (userId) => {
    const container = document.querySelector("[data-wallet-transactions]");
    if (!(container instanceof HTMLElement)) return;
    showState(container, "Loading wallet ledger…");
    try {
      const { entries } = await apiRequest(`/api/admin/wallets/${userId}/transactions`);
      const rows = (Array.isArray(entries) ? entries : []).map((entry) => {
        const row = make("tr");
        const operation = make("td");
        operation.append(make("strong", "", `${entry.operation_type} · ${entry.amount} ${entry.balance_type} Coins`));
        operation.append(make("small", "", entry.source));
        row.append(operation);
        row.append(make("td", "", `${entry.balance_before} → ${entry.balance_after} (${entry.total_balance_before} → ${entry.total_balance_after} total)`));
        row.append(make("td", "", `${entry.reference_type ?? "—"}${entry.reference_id ? ` #${entry.reference_id}` : ""}`));
        row.append(make("td", "", formatDate(entry.created_at)));
        return row;
      });
      if (!rows.length) showState(container, "No Coins ledger entries are recorded for this account.");
      else container.replaceChildren(make("h3", "", `Recent ledger entries · user ${userId}`), buildTable(["Operation", "Balance", "Reference", "Time (UTC)"], rows));
    } catch (error) {
      showState(container, error instanceof Error ? error.message : "Wallet transaction history could not be loaded.", true);
    }
  };

  const bindDirectorySearch = (selector, loader, refreshSelector) => {
    const form = document.querySelector(selector);
    if (form instanceof HTMLFormElement) {
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        const values = new FormData(form);
        loader(String(values.get("q") ?? ""), true);
      });
    }
    document.querySelector(refreshSelector)?.addEventListener("click", () => {
      if (form instanceof HTMLFormElement) form.reset();
      loader("", true);
    });
  };

  bindDirectorySearch("[data-admin-user-search]", (query) => loadAdminUsers(query, false, true), "[data-refresh-users]");
  bindDirectorySearch("[data-wallet-search]", loadAdminWallets, "[data-refresh-wallets]");

  const bindCommunityEventForm = () => {
    const form = document.querySelector("[data-community-event-form]");
    if (!(form instanceof HTMLFormElement)) return;
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submit = form.querySelector('button[type="submit"]');
      if (submit instanceof HTMLButtonElement) submit.disabled = true;
      const values = new FormData(form);
      const toIsoSeconds = (value) => new Date(String(value)).toISOString().replace(/\.\d{3}Z$/, "Z");
      const options = String(values.get("options") ?? "").split(/\r?\n/).map((option) => option.trim()).filter(Boolean);
      try {
        await postAdminMutation("/api/admin/events", "POST", {
          title: String(values.get("title") ?? "").trim(),
          description: String(values.get("description") ?? "").trim(),
          starts_at: toIsoSeconds(values.get("starts_at")),
          ends_at: toIsoSeconds(values.get("ends_at")),
          options,
        });
        form.reset();
        notify("Draft event created. Publish it when ready.");
        loaded.delete("community-events");
        await loadCommunityEvents(true);
      } catch (error) {
        notify(error instanceof Error ? error.message : "Community event could not be created.", true);
      } finally {
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
      }
    });
  };

  bindCommunityEventForm();

  document.addEventListener("submit", async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLFormElement)) return;
    if (target.matches("[data-event-edit-form]")) {
      event.preventDefault();
      const submit = target.querySelector('button[type="submit"]');
      if (submit instanceof HTMLButtonElement) submit.disabled = true;
      const values = new FormData(target);
      const toIsoSeconds = (value) => new Date(String(value)).toISOString().replace(/\.\d{3}Z$/, "Z");
      const payload = {
        title: String(values.get("title") ?? "").trim(),
        description: String(values.get("description") ?? "").trim(),
        starts_at: toIsoSeconds(values.get("starts_at")),
        ends_at: toIsoSeconds(values.get("ends_at")),
      };
      if (values.has("options")) {
        payload.options = String(values.get("options") ?? "").split(/\r?\n/).map((option) => option.trim()).filter(Boolean);
      }
      try {
        await postAdminMutation(`/api/admin/events/${target.dataset.eventId}`, "PATCH", payload);
        notify("Event details saved.");
        loaded.delete("community-events");
        await loadCommunityEvents(true);
      } catch (error) {
        notify(error instanceof Error ? error.message : "Event details could not be saved.", true);
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
      }
    } else if (target.matches("[data-event-reward-form]")) {
      event.preventDefault();
      const submit = target.querySelector('button[type="submit"]');
      if (submit instanceof HTMLButtonElement) submit.disabled = true;
      const values = new FormData(target);
      try {
        const eventId = target.dataset.eventId;
        await postAdminMutation(`/api/admin/events/${eventId}/rewards`, "POST", {
          user_id: String(values.get("user_id") ?? ""),
          amount: String(values.get("amount") ?? ""),
          reason: String(values.get("reason") ?? "").trim(),
        });
        target.reset();
        notify("Event reward issued to the eligible participant.");
      } catch (error) {
        notify(error instanceof Error ? error.message : "Event reward could not be issued.", true);
      } finally {
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
      }
    } else if (target.matches("[data-suggestion-id]")) {
      event.preventDefault();
      const submitter = event.submitter;
      if (!(submitter instanceof HTMLButtonElement) || !["accepted", "rejected"].includes(submitter.value)) return;
      const values = new FormData(target);
      [...target.querySelectorAll("button")].forEach((button) => { button.disabled = true; });
      try {
        await postAdminMutation(`/api/admin/event-suggestions/${target.dataset.suggestionId}/review`, "POST", {
          decision: submitter.value,
          note: String(values.get("note") ?? "").trim(),
        });
        notify(`Suggestion ${submitter.value}.`);
        loaded.delete("event-suggestions");
        await loadEventSuggestions(true);
      } catch (error) {
        notify(error instanceof Error ? error.message : "Suggestion review could not be saved.", true);
        [...target.querySelectorAll("button")].forEach((button) => { button.disabled = false; });
      }
    }
  });

  document.addEventListener("click", async (event) => {
    const button = event.target instanceof Element ? event.target.closest("[data-admin-action]") : null;
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    const { adminAction, id } = button.dataset;
    button.disabled = true;
    try {
      if (adminAction === "event-activity") {
        const container = document.querySelector("[data-event-results-list]");
        const { entries } = await apiRequest(`/api/admin/events/${id}/activity`);
        if (container instanceof HTMLElement) {
          const card = make("div", "admin-panel");
          card.append(make("h3", "", `Activity for event ${id}`));
          const rows = entries.map((entry) => {
            const row = make("tr");
            row.append(make("td", "", entry.activity_type));
            row.append(make("td", "", entry.actor_username ?? "User"));
            row.append(make("td", "", formatDate(entry.created_at)));
            return row;
          });
          card.append(rows.length ? buildTable(["Activity", "Actor", "Time (UTC)"], rows) : make("p", "admin-state", "No event activity has been recorded."));
          container.append(card);
        }
      } else if (adminAction === "approve-results") {
        if (!window.confirm(translate("Approve and publish the server-calculated voting results?"))) return;
        await postAdminMutation(`/api/admin/events/${id}/results/approve`, "POST", {});
        notify("Event results approved and published.");
        loaded.delete("community-events");
        await loadCommunityEvents(true);
      } else if (adminAction === "publish" || adminAction === "paused" || adminAction === "cancelled") {
        const status = adminAction === "publish" ? "published" : adminAction;
        if (status === "cancelled" && !window.confirm(translate("Cancel this event? This cannot be undone."))) return;
        await postAdminMutation(`/api/admin/events/${id}`, "PATCH", { status });
        notify(`Event ${status.replaceAll("_", " ")}.`);
        loaded.delete("community-events");
        await loadCommunityEvents(true);
      } else if (adminAction === "diamond-package-toggle") {
        await postAdminMutation(`/api/admin/diamonds/packages/${id}`, "PATCH", { is_active: button.dataset.active === "true" });
        notify("Diamond package status updated.");
        loaded.delete("diamond-packages");
        await loadDiamondPackages(true);
      } else if (adminAction === "diamond-order-history") {
        await loadDiamondOrderHistory(id);
      } else if (adminAction === "wallet-transactions") {
        await loadWalletTransactions(id);
      } else if (adminAction === "diamond-order-action") {
        const transition = button.dataset.transition;
        const note = window.prompt(translate("Enter an administrative note (required):"));
        if (note === null) return;
        const payload = { action: transition, note: note.trim() };
        if (transition === "mark_paid") {
          const reference = window.prompt(translate("Optional payment reference:"));
          if (reference) payload.payment_reference = reference.trim();
        }
        await postAdminMutation(`/api/admin/diamonds/orders/${id}/actions`, "POST", payload);
        notify("Diamond order state updated.");
        await loadDiamondOrders("", true);
        await loadDiamondOrderHistory(id);
      }
    } catch (error) {
      notify(error instanceof Error ? error.message : "The requested admin action failed.", true);
    } finally {
      if (button.isConnected) button.disabled = false;
    }
  });

  const diamondPackageForm = document.querySelector("[data-diamond-package-form]");
  if (diamondPackageForm instanceof HTMLFormElement) {
    diamondPackageForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submit = diamondPackageForm.querySelector('button[type="submit"]');
      if (submit instanceof HTMLButtonElement) submit.disabled = true;
      const values = new FormData(diamondPackageForm);
      try {
        await postAdminMutation("/api/admin/diamonds/packages", "POST", {
          name: String(values.get("name") ?? "").trim(),
          diamond_amount: String(values.get("diamond_amount") ?? ""),
          price: String(values.get("price") ?? ""),
          currency_code: String(values.get("currency_code") ?? "").trim().toUpperCase(),
        });
        diamondPackageForm.reset();
        notify("Inactive diamond package created.");
        loaded.delete("diamond-packages");
        await loadDiamondPackages(true);
      } catch (error) {
        notify(error instanceof Error ? error.message : "Diamond package could not be created.", true);
      } finally {
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
      }
    });
  }

  const orderSearchForm = document.querySelector("[data-diamond-order-search]");
  if (orderSearchForm instanceof HTMLFormElement) {
    orderSearchForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const values = new FormData(orderSearchForm);
      await loadDiamondOrders(String(values.get("q") ?? ""), true);
    });
  }
  document.querySelector("[data-refresh-events]")?.addEventListener("click", () => loadCommunityEvents(true));
  document.querySelector("[data-refresh-diamond-orders]")?.addEventListener("click", () => {
    if (orderSearchForm instanceof HTMLFormElement) orderSearchForm.reset();
    loadDiamondOrders("", true);
  });

  const openSection = (section) => {
    const target = panels.find((panel) => panel.dataset.panel === section);
    if (!target) return;
    panels.forEach((panel) => {
      const active = panel === target;
      panel.hidden = !active;
      panel.classList.toggle("is-active", active);
    });
    navItems.forEach((item) => {
      const active = item.dataset.section === section;
      item.classList.toggle("is-active", active);
      if (active) item.setAttribute("aria-current", "page");
      else item.removeAttribute("aria-current");
    });
    if (section === "orders") loadOrders();
    if (section === "games") loadGames();
    if (section === "users") loadAdminUsers();
    if (section === "wallet") loadAdminWallets();
    if (section === "admins") loadAdminUsers("", true);
    if (section === "audit") loadAudit();
    if (section === "homepage-rewards") loadHomepageRewardConfig();
    if (section === "community-events" || section === "event-results") loadCommunityEvents();
    if (section === "event-suggestions") loadEventSuggestions();
    if (section === "diamond-packages") loadDiamondPackages();
    if (section === "diamond-orders") loadDiamondOrders();
    if (section === "coin-config") {
      loadCatalog("packages");
      loadCatalog("methods");
    }
  };

  navItems.forEach((item) => {
    item.addEventListener("click", () => {
      if (!item.disabled && item.dataset.section) openSection(item.dataset.section);
    });
  });
  document.querySelector("[data-open-section='audit']")?.addEventListener("click", () => openSection("audit"));
  document.querySelector("[data-refresh-orders]")?.addEventListener("click", () => loadOrders(true));
  document.querySelector("[data-refresh-audit]")?.addEventListener("click", () => loadAudit(true));

  document.querySelector("[data-logout]")?.addEventListener("click", async (event) => {
    const button = event.currentTarget;
    if (!(button instanceof HTMLButtonElement)) return;
    button.disabled = true;
    try {
      await postAdminMutation("/api/auth/logout", "POST", {});
      window.location.assign("/account.html");
    } catch (error) {
      notify(error instanceof Error ? error.message : "Sign out failed.", true);
      button.disabled = false;
    }
  });

  const requestedSection = new URLSearchParams(window.location.search).get("section");
  const requestedNavItem = navItems.find((item) => item.dataset.section === requestedSection && !item.disabled);
  if (requestedNavItem?.dataset.section) {
    openSection(requestedNavItem.dataset.section);
  } else {
    loadGames();
    if (permissions.has("review_coin_orders")) loadOrders();
    if (permissions.has("view_audit_logs")) loadAudit();
    if (permissions.has("manage_homepage_rewards")) loadHomepageRewardConfig();
    if (permissions.has("view_admin_users")) loadAdminUsers("", true);
    if (permissions.has("manage_community_events")) loadCommunityEvents();
    if (permissions.has("review_event_suggestions")) loadEventSuggestions();
    if (permissions.has("manage_diamond_packages")) loadDiamondPackages();
    if (permissions.has("manage_diamond_orders")) loadDiamondOrders();
    if (permissions.has("manage_coin_packages") || permissions.has("manage_coin_payment_methods")) {
      loadCatalog("packages");
      loadCatalog("methods");
    }
  }
})();
