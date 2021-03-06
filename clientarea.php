<?php

define("CLIENTAREA", true);
require __DIR__ . "/init.php";
require ROOTDIR . "/includes/clientfunctions.php";
require ROOTDIR . "/includes/gatewayfunctions.php";
require ROOTDIR . "/includes/ccfunctions.php";
require ROOTDIR . "/includes/domainfunctions.php";
require ROOTDIR . "/includes/registrarfunctions.php";
require ROOTDIR . "/includes/customfieldfunctions.php";
require ROOTDIR . "/includes/invoicefunctions.php";
require ROOTDIR . "/includes/configoptionsfunctions.php";
$action = $whmcs->get_req_var("action");
$sub = $whmcs->get_req_var("sub");
$id = (int) $whmcs->get_req_var("id");
$modop = $whmcs->get_req_var("modop");
$submit = $whmcs->get_req_var("submit");
$save = $whmcs->get_req_var("save");
$q = $whmcs->get_req_var("q");
$paymentmethod = WHMCS\Gateways::makeSafeName($whmcs->get_req_var("paymentmethod"));
$addRenewalToCart = $whmcs->get_req_var("addRenewalToCart");
if ($addRenewalToCart) {
    check_token();
    $renewID = $whmcs->get_req_var("renewID");
    $renewalPeriod = $whmcs->get_req_var("period");
    $_SESSION["cart"]["renewals"][$renewID] = $renewalPeriod;
    WHMCS\Terminus::getInstance()->doExit();
} else {
    if ($action == "resendVerificationEmail") {
        check_token();
        $clientDetails = WHMCS\User\Client::find(WHMCS\Session::get("uid"));
        if (!is_null($clientDetails)) {
            $clientDetails->sendEmailAddressVerification();
        }
        WHMCS\Terminus::getInstance()->doExit();
    } else {
        if ($action == "parseMarkdown") {
            check_token();
            $markup = new WHMCS\View\Markup\Markup();
            echo json_encode(array("body" => $markup->transform($whmcs->get_req_var("content"), "markdown")));
            WHMCS\Terminus::getInstance()->doExit();
        } else {
            if ($action == "manage-service") {
                check_token();
                $serviceId = App::getFromRequest("service-id");
                $server = new WHMCS\Module\Server();
                if (substr($serviceId, 0, 1) == "a") {
                    $server->loadByAddonId((int) substr($serviceId, 1));
                    $errorPrependText = "An error occurred when managing Service Addon ID: " . (int) substr($serviceId, 1) . ": ";
                } else {
                    $serviceId = (int) $serviceId;
                    $server->loadByServiceID($serviceId);
                    $errorPrependText = "An error occurred when managing Service ID: " . $serviceId . ": ";
                }
                $serviceServerParams = $server->buildParams();
                $allowedModuleFunctions = array();
                $clientAreaAllowedFunctions = $server->call("ClientAreaAllowedFunctions");
                if (is_array($clientAreaAllowedFunctions) && !array_key_exists("error", $clientAreaAllowedFunctions)) {
                    foreach ($clientAreaAllowedFunctions as $functionName) {
                        if (is_string($functionName)) {
                            $allowedModuleFunctions[] = $functionName;
                        }
                    }
                }
                $clientAreaCustomButtons = $server->call("ClientAreaCustomButtonArray");
                if (is_array($clientAreaCustomButtons) && !array_key_exists("error", $clientAreaAllowedFunctions)) {
                    foreach ($clientAreaCustomButtons as $buttonLabel => $functionName) {
                        if (is_string($functionName)) {
                            $allowedModuleFunctions[] = $functionName;
                        }
                    }
                }
                if (WHMCS\Session::get("uid") == $serviceServerParams["userid"]) {
                    if (in_array("manage_order", $allowedModuleFunctions) && $server->functionExists("manage_order")) {
                        $apiResponse = $server->call("manage_order");
                        $apiResponse = isset($apiResponse["jsonResponse"]) ? $apiResponse["jsonResponse"] : array();
                        if (is_array($apiResponse) && !empty($apiResponse["success"])) {
                            $response = array("redirect" => $apiResponse["redirect"]);
                        } else {
                            $errorMsg = isset($apiResponse["error"]) ? $apiResponse["error"] : "An unknown error occurred";
                            $response = array("error" => $errorMsg);
                        }
                    } else {
                        $response = array("error" => "Function Not Allowed");
                    }
                } else {
                    $response = array("error" => "Access Denied");
                }
                echo json_encode($response);
                WHMCS\Terminus::getInstance()->doExit();
            } else {
                if ($action == "dismiss-email-banner") {
                    check_token();
                    WHMCS\Session::setAndRelease("DismissEmailVerificationBannerForSession", true);
                    echo json_encode(array("success" => true));
                    WHMCS\Terminus::getInstance()->doExit();
                }
            }
        }
    }
}
$activeLanguage = WHMCS\Session::get("Language");
if ($action == "changesq" || $whmcs->get_req_var("2fasetup")) {
    $action = "security";
}
$ca = new WHMCS\ClientArea();
$ca->setPageTitle($whmcs->get_lang("clientareatitle"));
$ca->addToBreadCrumb("index.php", $whmcs->get_lang("globalsystemname"))->addToBreadCrumb("clientarea.php", $whmcs->get_lang("clientareatitle"));
$ca->initPage();
$legacyClient = new WHMCS\Client($ca->getClient());
$clientInformation = $legacyClient->getClientModel();
$clientInformationAvailable = is_null($clientInformation) ? false : true;
$verifyEmailAddressEnabled = WHMCS\Config\Setting::getValue("EnableEmailVerification");
$emailVerificationPending = false;
$emailVerificationRecentlyCleared = false;
$verificationIdNotValid = false;
if ($verifyEmailAddressEnabled) {
    $verificationId = $whmcs->get_req_var("verificationId");
    if (!empty($verificationId)) {
        $transientData = WHMCS\TransientData::getInstance();
        $transientDataName = $transientData->retrieveByData($verificationId);
        $initialVerificationId = WHMCS\Session::get("initialVerificationId");
        $smartyvalues["verificationId"] = $verificationId;
        $smartyvalues["transientDataName"] = $transientDataName;
        if (!$clientInformationAvailable) {
            WHMCS\Session::set("initialVerificationId", $verificationId);
        } else {
            if ($initialVerificationId != $verificationId && !$clientInformation->emailVerified) {
                WHMCS\Session::delete("uid");
                WHMCS\Session::set("initialVerificationId", $verificationId);
            } else {
                if ($transientDataName) {
                    $clientInformation->emailVerified = true;
                    $clientInformation->save();
                    run_hook("ClientEmailVerificationComplete", array("userId" => $ca->getUserID()));
                    $emailVerificationRecentlyCleared = true;
                    $transientData->delete($transientDataName);
                    WHMCS\Session::delete("initialVerificationId");
                } else {
                    if (!$clientInformation->emailVerified) {
                        $verificationIdNotValid = true;
                    }
                }
            }
        }
    }
    if ($clientInformationAvailable) {
        $isEmailAddressVerified = $clientInformation->isEmailAddressVerified();
        if (!$isEmailAddressVerified && !WHMCS\Session::get("DismissEmailVerificationBannerForSession")) {
            $emailVerificationPending = true;
        }
    }
}
$smartyvalues["emailVerificationPending"] = $emailVerificationPending;
$ca->requireLogin();
if ($emailVerificationRecentlyCleared) {
    $smartyvalues["emailVerificationIdValid"] = true;
} else {
    if ($verificationIdNotValid) {
        $smartyvalues["emailVerificationIdValid"] = false;
    }
}
if ($action == "hosting") {
    $ca->addToBreadCrumb("clientarea.php?action=hosting", $whmcs->get_lang("clientareanavhosting"));
}
if (in_array($action, array("products", "services", "cancel"))) {
    $ca->addToBreadCrumb("clientarea.php?action=products", $whmcs->get_lang("clientareaproducts"));
}
if (in_array($action, array("domains", "domaindetails", "domaincontacts", "domaindns", "domainemailforwarding", "domaingetepp", "domainregisterns", "domainaddons"))) {
    $ca->addToBreadCrumb("clientarea.php?action=domains", $whmcs->get_lang("clientareanavdomains"));
}
if ($action == "invoices") {
    $ca->addToBreadCrumb("clientarea.php?action=invoices", $whmcs->get_lang("invoices"));
}
if ($action == "emails") {
    $ca->addToBreadCrumb("clientarea.php?action=emails", $whmcs->get_lang("clientareaemails"));
}
if ($action == "addfunds") {
    $ca->addToBreadCrumb("clientarea.php?action=addfunds", $whmcs->get_lang("addfunds"));
}
if ($action == "masspay") {
    $ca->addToBreadCrumb("clientarea.php?action=masspay" . ($whmcs->get_req_var("all") ? "&all=true" : "") . "#", $whmcs->get_lang("masspaytitle"));
}
if ($action == "quotes") {
    $ca->addToBreadCrumb("clientarea.php?action=quotes", $whmcs->get_lang("quotestitle"));
}
$currency = $legacyClient->getCurrency();
if (substr($action, 0, 6) == "domain" && $action != "domains") {
    $domainID = $whmcs->get_req_var("id");
    if (!$domainID) {
        $domainID = $whmcs->get_req_var("domainid");
    }
    $domains = new WHMCS\Domains();
    $domainData = $domains->getDomainsDatabyID($domainID);
    if (!$domainData) {
        redir("action=domains", "clientarea.php");
    }
    $domainModel = WHMCS\Domain\Domain::find($domainData["id"]);
    $ca->setDisplayTitle(Lang::trans("managing") . " " . $domainData["domain"]);
    $domainName = new WHMCS\Domains\Domain($domainData["domain"]);
    $managementOptions = $domains->getManagementOptions();
    $ca->assign("managementoptions", $managementOptions);
}
$ca->assign("action", $action);
$ca->assign("clientareaaction", $action);
if ($action == "") {
    $templateVars = $ca->getTemplateVariables();
    $ca->setDisplayTitle(Lang::trans("welcomeback") . ", " . $templateVars["loggedinuser"]["firstname"]);
    $ca->setTemplate("clientareahome");
    $clientId = $ca->getClient()->id;
    $panels = array();
    if (checkContactPermission("invoices", true)) {
        $overdueInvoices = WHMCS\Billing\Invoice::with("transactions")->whereUserid($clientId)->overdue()->massPay(false)->get();
        if (0 < count($overdueInvoices)) {
            $msg = Lang::trans("clientHomePanels.overdueInvoicesMsg", array(":numberOfInvoices" => count($overdueInvoices), ":balanceDue" => formatCurrency($overdueInvoices->sum("balance"))));
            $panels[] = array("name" => "Overdue Invoices", "label" => Lang::trans("clientHomePanels.overdueInvoices"), "icon" => "fa-calculator", "extras" => array("color" => "red", "btn-icon" => "fa-arrow-right", "btn-link" => "clientarea.php?action=masspay&all=true", "btn-text" => Lang::trans("invoicespaynow")), "bodyHtml" => "<p>" . $msg . "</p>", "order" => "10");
        } else {
            $unpaidInvoices = WHMCS\Billing\Invoice::with("transactions")->whereUserid($clientId)->unpaid()->massPay(false)->get();
            if (0 < count($unpaidInvoices)) {
                $msg = Lang::trans("clientHomePanels.unpaidInvoicesMsg", array(":numberOfInvoices" => count($unpaidInvoices), ":balanceDue" => formatCurrency($unpaidInvoices->sum("balance"))));
                $panels[] = array("name" => "Unpaid Invoices", "label" => Lang::trans("clientHomePanels.unpaidInvoices"), "icon" => "fa-calculator", "extras" => array("color" => "red", "btn-icon" => "fa-arrow-right", "btn-link" => "clientarea.php?action=invoices", "btn-text" => Lang::trans("viewAll")), "bodyHtml" => "<p>" . $msg . "</p>", "order" => "10");
            }
        }
    }
    if (checkContactPermission("domains", true)) {
        $domainsDueWithin30Days = $ca->getClient()->domains()->nextDueBefore(Carbon\Carbon::now()->addDays(30))->count();
        if (0 < $domainsDueWithin30Days) {
            $msg = Lang::trans("clientHomePanels.domainsExpiringSoonMsg", array(":days" => 30, ":numberOfDomains" => $domainsDueWithin30Days));
            $extras = array();
            if (WHMCS\Config\Setting::getValue("EnableDomainRenewalOrders")) {
                $extras = array("btn-icon" => "fa-refresh", "btn-link" => "cart.php?gid=renewals", "btn-text" => Lang::trans("domainsrenewnow"));
            }
            $extras["color"] = "midnight-blue";
            $panels[] = array("name" => "Domains Expiring Soon", "label" => Lang::trans("clientHomePanels.domainsExpiringSoon"), "icon" => "fa-globe", "extras" => $extras, "bodyHtml" => "<p>" . $msg . "</p>", "order" => "50");
        }
    }
    if (checkContactPermission("products", true)) {
        $servicesList = array();
        $services = $ca->getClient()->services()->whereDomainstatus("Active")->orderBy("id", "desc")->limit(101)->get();
        foreach ($services as $service) {
            $servicesList[] = array("uri" => "clientarea.php?action=productdetails&id=" . $service->id, "label" => $service->product->productGroup->name . " - " . $service->product->name . "<br /><span class=\"text-domain\">" . $service->domain . "</span>");
        }
        $servicesPanel = array("name" => "Active Products/Services", "label" => Lang::trans("clientHomePanels.activeProductsServices"), "icon" => "fa-cube", "extras" => array("color" => "gold", "btn-icon" => "fa-plus", "btn-link" => "clientarea.php?action=services", "btn-text" => Lang::trans("viewAll")), "children" => $servicesList, "order" => "100");
        if (count($servicesList) == 0) {
            $servicesPanel["bodyHtml"] = "<p>" . Lang::trans("clientHomePanels.activeProductsServicesNone") . "</p>";
        } else {
            if (100 < count($servicesList)) {
                unset($servicesPanel["children"][100]);
                $servicesPanel["bodyHtml"] = "<p>" . Lang::trans("clientHomePanels.showingRecent100") . ".</p>";
            }
        }
        $panels[] = $servicesPanel;
    }
    if (checkContactPermission("orders", true) && (WHMCS\Config\Setting::getValue("AllowRegister") || WHMCS\Config\Setting::getValue("AllowTransfer"))) {
        $bodyContent = "<form method=\"post\" action=\"domainchecker.php\">\n            <div class=\"input-group margin-10\">\n                <input type=\"text\" name=\"domain\" class=\"form-control\" />\n                <div class=\"input-group-btn\">";
        if (WHMCS\Config\Setting::getValue("AllowRegister")) {
            $bodyContent .= "\n                    <input type=\"submit\" value=\"" . Lang::trans("domainsregister") . "\" class=\"btn btn-success\" />";
        }
        if (WHMCS\Config\Setting::getValue("AllowTransfer")) {
            $bodyContent .= "\n                    <input type=\"submit\" name=\"transfer\" value=\"" . Lang::trans("domainstransfer") . "\" class=\"btn\" />";
        }
        $bodyContent .= "\n                </div>\n            </div>\n        </form>";
        $panels[] = array("name" => "Register a New Domain", "label" => Lang::trans("navregisterdomain"), "icon" => "fa-globe", "extras" => array("color" => "emerald"), "bodyHtml" => $bodyContent, "order" => "200");
    }
    if (WHMCS\Config\Setting::getValue("AffiliateEnabled") && checkContactPermission("affiliates", true) && !is_null($affiliate = $ca->getClient()->affiliate)) {
        $currencyLimit = convertCurrency(WHMCS\Config\Setting::getValue("AffiliatePayout"), 1, $currency["id"]);
        $amountUntilWithdrawal = $currencyLimit - $affiliate->balance;
        if (0 < $amountUntilWithdrawal) {
            $msgTemplate = "clientHomePanels.affiliateSummary";
        } else {
            $msgTemplate = "clientHomePanels.affiliateSummaryWithdrawalReady";
        }
        $msg = Lang::trans($msgTemplate, array(":commissionBalance" => formatCurrency($affiliate->balance), ":amountUntilWithdrawalLevel" => formatCurrency($amountUntilWithdrawal)));
        $panels[] = array("name" => "Affiliate Program", "label" => Lang::trans("clientHomePanels.affiliateProgram"), "icon" => "fa-users", "extras" => array("color" => "teal", "btn-icon" => "fa-arrow-right", "btn-link" => "affiliates.php", "btn-text" => Lang::trans("moreDetails")), "bodyHtml" => "<p>" . $msg . "</p>", "order" => "300");
    }
    if (!function_exists("AddNote")) {
        require ROOTDIR . DIRECTORY_SEPARATOR . "includes" . DIRECTORY_SEPARATOR . "ticketfunctions.php";
    }
    $tickets = array();
    $statusfilter = array();
    $result = select_query("tblticketstatuses", "title", array("showactive" => "1"));
    while ($data = mysql_fetch_array($result)) {
        $statusfilter[] = $data[0];
    }
    $result = select_query("tbltickets", "", array("userid" => (int) $legacyClient->getID(), "status" => array("sqltype" => "IN", "values" => $statusfilter), "merged_ticket_id" => 0), "lastreply", "DESC");
    while ($data = mysql_fetch_array($result)) {
        $id = $data["id"];
        $tid = $data["tid"];
        $c = $data["c"];
        $deptid = $data["did"];
        $date = $data["date"];
        $date = fromMySQLDate($date, 1, 1);
        $subject = $data["title"];
        $status = $data["status"];
        $urgency = $data["urgency"];
        $lastreply = $data["lastreply"];
        $lastreply = fromMySQLDate($lastreply, 1, 1);
        $clientunread = $data["clientunread"];
        $htmlFormattedStatus = getStatusColour($status);
        $dept = getDepartmentName($deptid);
        $urgency = Lang::trans("supportticketsticketurgency" . strtolower($urgency));
        $statusClass = WHMCS\View\Helper::generateCssFriendlyClassName($status);
        $tickets[] = array("id" => $id, "tid" => $tid, "c" => $c, "date" => $date, "department" => $dept, "subject" => $subject, "status" => $htmlFormattedStatus, "statusClass" => $statusClass, "urgency" => $urgency, "lastreply" => $lastreply, "unread" => $clientunread);
    }
    $ca->assign("tickets", $tickets);
    if (checkContactPermission("tickets", true)) {
        $ticketsList = array();
        $rawStatusColors = Illuminate\Database\Capsule\Manager::table("tblticketstatuses")->get();
        $ticketRows = Illuminate\Database\Capsule\Manager::table("tbltickets")->where("userid", "=", $legacyClient->getID())->where("merged_ticket_id", "=", "0")->orderBy("lastreply", "DESC")->limit(10)->get();
        foreach ($ticketRows as $data) {
            $id = $data->id;
            $tid = $data->tid;
            $c = $data->c;
            $subject = $data->title;
            $status = $data->status;
            $lastreply = $data->lastreply;
            $clientunread = $data->clientunread;
            $lastreply = fromMySQLDate($lastreply, 1, 1);
            $statusColors = array();
            foreach ($rawStatusColors as $color) {
                $statusColors[$color->title] = $color->color;
            }
            $langStatus = preg_replace("/[^a-z]/i", "", strtolower($status));
            if (Lang::trans("supportticketsstatus" . $langStatus) != "supportticketsstatus" . $langStatus) {
                $statusText = Lang::trans("supportticketsstatus" . $langStatus);
            } else {
                $statusText = $status;
            }
            $ticketsList[] = array("uri" => "viewticket.php?tid=" . $tid . "&c=" . $c, "label" => ($clientunread ? "<strong>" : "") . "#" . $tid . " - " . $subject . ($clientunread ? "</strong> " : " ") . "<label class=\"label\" style=\"background-color: " . $statusColors[$status] . "\">" . $statusText . "</label><br />" . "<small>" . Lang::trans("supportticketsticketlastupdated") . ": " . $lastreply . "</small>");
        }
        $ticketsPanel = array("name" => "Recent Support Tickets", "label" => Lang::trans("clientHomePanels.recentSupportTickets"), "icon" => "fa-comments", "extras" => array("color" => "blue", "btn-icon" => "fa-plus", "btn-link" => "submitticket.php", "btn-text" => Lang::trans("opennewticket")), "children" => $ticketsList, "order" => "150");
        if (count($ticketsList) == 0) {
            $ticketsPanel["bodyHtml"] = "<p>" . Lang::trans("clientHomePanels.recentSupportTicketsNone") . "</p>";
        }
        $panels[] = $ticketsPanel;
    }
    $invoice = new WHMCS\Invoice();
    $invoices = $invoice->getInvoices("Unpaid", $legacyClient->getID(), "id", "DESC");
    $ca->assign("invoices", $invoices);
    $ca->assign("totalbalance", $invoice->getTotalBalanceFormatted());
    $ca->assign("masspay", WHMCS\Config\Setting::getValue("EnableMassPay"));
    $ca->assign("defaultpaymentmethod", getGatewayName($clientsdetails["defaultgateway"]));
    $ca->assign("addfundsenabled", WHMCS\Config\Setting::getValue("AddFundsEnabled"));
    $files = $legacyClient->getFiles($legacyClient->getID());
    $ca->assign("files", $files);
    if (0 < count($files)) {
        $filesList = array();
        foreach ($files as $file) {
            $filesList[] = array("label" => $file["title"] . "<br /><small>" . $file["date"] . "</small>", "uri" => "dl.php?type=f&id=" . $file["id"]);
        }
        $panels[] = array("name" => "Your Files", "label" => Lang::trans("clientareafiles"), "icon" => "fa-download", "extras" => array("color" => "purple"), "children" => $filesList, "order" => "250");
    }
    $announcementsList = array();
    $announcements = WHMCS\Announcement\Announcement::wherePublished(true)->orderBy("date", "DESC")->take(3)->get();
    foreach ($announcements as $announcement) {
        $announcementTitle = $announcement->title;
        $announcementContent = $announcement->announcement;
        if ($activeLanguage) {
            try {
                $announcementLocal = WHMCS\Announcement\Announcement::whereParentid($announcement->id)->whereLanguage($activeLanguage)->firstOrFail();
                $announcementTitle = $announcementLocal->title;
                $announcementContent = $announcementLocal->announcement;
            } catch (Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            }
        }
        $uri = getModRewriteFriendlyString($announcementTitle);
        $announcementsList[] = array("id" => $announcement->id, "date" => fromMySQLDate($announcement->date, 0, 1), "title" => $announcementTitle, "urlfriendlytitle" => $uri, "text" => $announcementContent, "label" => $announcementTitle . "<br /><span class=\"text-last-updated\">" . fromMySQLDate($announcement->publishDate, 0, 1) . "</span>", "uri" => routePath("announcement-view", $announcement->id, $uri));
    }
    $smartyvalues["announcements"] = $announcementsList;
    $panels[] = array("name" => "Recent News", "label" => Lang::trans("clientHomePanels.recentNews"), "icon" => "fa-newspaper-o", "extras" => array("color" => "asbestos", "btn-icon" => "fa-arrow-right", "btn-link" => routePath("announcement-index"), "btn-text" => Lang::trans("viewAll")), "children" => $announcementsList, "order" => "500");
    $smartyvalues["registerdomainenabled"] = (bool) WHMCS\Config\Setting::getValue("AllowRegister");
    $smartyvalues["transferdomainenabled"] = (bool) WHMCS\Config\Setting::getValue("AllowTransfer");
    $smartyvalues["owndomainenabled"] = (bool) WHMCS\Config\Setting::getValue("AllowOwnDomain");
    $captcha = clientAreaInitCaptcha();
    $smartyvalues["captcha"] = $captcha;
    $smartyvalues["recaptchahtml"] = clientAreaReCaptchaHTML();
    $smartyvalues["contacts"] = $legacyClient->getContacts();
    $addons_html = run_hook("ClientAreaHomepage", array());
    $ca->assign("addons_html", $addons_html);
    $factory = new WHMCS\View\Menu\MenuFactory();
    $item = $factory->getLoader()->load(array("name" => "ClientAreaHomePagePanels", "children" => $panels));
    run_hook("ClientAreaHomepagePanels", array($item), true);
    $smartyvalues["panels"] = WHMCS\View\Menu\Item::sort($item);
    $ca->addOutputHookFunction("ClientAreaPageHome");
} else {
    if ($action == "details") {
        checkContactPermission("profile");
        $ca->setDisplayTitle(Lang::trans("clientareanavdetails"));
        $ca->setTemplate("clientareadetails");
        $ca->addToBreadCrumb("clientarea.php?action=details", Lang::trans("clientareanavdetails"));
        $uneditablefields = explode(",", WHMCS\Config\Setting::getValue("ClientsProfileUneditableFields"));
        $smartyvalues["uneditablefields"] = $uneditablefields;
        $e = "";
        $exdetails = array();
        $ca->assign("successful", false);
        if ($save) {
            check_token();
            $e = checkDetailsareValid($legacyClient->getID(), false);
            if ($e) {
                $ca->assign("errormessage", $e);
            } else {
                $legacyClient->updateClient();
                redir("action=details&success=1");
            }
        }
        if ($whmcs->get_req_var("success")) {
            $ca->assign("successful", true);
        }
        if (!$e) {
            $exdetails = $legacyClient->getDetails();
        }
        $countries = new WHMCS\Utility\Country();
        $ca->assign("clientfirstname", $whmcs->get_req_var_if($e, "firstname", $exdetails));
        $ca->assign("clientlastname", $whmcs->get_req_var_if($e, "lastname", $exdetails));
        $ca->assign("clientcompanyname", $whmcs->get_req_var_if($e, "companyname", $exdetails));
        $ca->assign("clientemail", $whmcs->get_req_var_if($e, "email", $exdetails));
        $ca->assign("clientaddress1", $whmcs->get_req_var_if($e, "address1", $exdetails));
        $ca->assign("clientaddress2", $whmcs->get_req_var_if($e, "address2", $exdetails));
        $ca->assign("clientcity", $whmcs->get_req_var_if($e, "city", $exdetails));
        $ca->assign("clientstate", $whmcs->get_req_var_if($e, "state", $exdetails));
        $ca->assign("clientpostcode", $whmcs->get_req_var_if($e, "postcode", $exdetails));
        $ca->assign("clientcountry", $countries->getName($whmcs->get_req_var_if($e, "country", $exdetails)));
        $ca->assign("clientcountriesdropdown", getCountriesDropDown($whmcs->get_req_var_if($e, "country", $exdetails), "", "", false, in_array("country", $uneditablefields)));
        $ca->assign("clientphonenumber", $whmcs->get_req_var_if($e, "phonenumberformatted", $exdetails));
        $ca->assign("customfields", getCustomFields("client", "", $legacyClient->getID(), "", "", $whmcs->get_req_var("customfield")));
        $ca->assign("contacts", $legacyClient->getContacts());
        $ca->assign("billingcid", $whmcs->get_req_var_if($e, "billingcid", $exdetails));
        $ca->assign("paymentmethods", showPaymentGatewaysList(array(), $legacyClient->getID()));
        $ca->assign("emailoptout", $whmcs->get_req_var_if($e, "emailoptout", $exdetails));
        $ca->assign("emailoptoutenabled", $whmcs->get_config("AllowClientsEmailOptOut"));
        $ca->assign("defaultpaymentmethod", $whmcs->get_req_var_if($e, "paymentmethod", $exdetails, "defaultgateway"));
        $ca->addOutputHookFunction("ClientAreaPageProfile");
    } else {
        if ($action == "contacts") {
            checkContactPermission("contacts");
            $ca->setDisplayTitle(Lang::trans("clientareanavcontacts"));
            $ca->setTemplate("clientareacontacts");
            $ca->addToBreadCrumb("clientarea.php?action=details", $whmcs->get_lang("clientareanavdetails"));
            $ca->addToBreadCrumb("clientarea.php?action=contacts", $whmcs->get_lang("clientareanavcontacts"));
            $contact_data = array();
            $contactid = $whmcs->get_req_var("contactid");
            if ($contactid) {
                if ($contactid == "new") {
                    redir("action=addcontact");
                }
                $id = (int) $contactid;
            }
            if ($id) {
                $contact_data = $legacyClient->getContact($id);
                if (!$contact_data) {
                    redir("action=contacts", "clientarea.php");
                }
                $id = $contact_data["id"];
            }
            if ($whmcs->get_req_var("delete")) {
                check_token();
                $legacyClient->deleteContact($id);
                redir("action=contacts");
            }
            $e = "";
            $smartyvalues["successful"] = false;
            if ($submit) {
                check_token();
                $errormessage = $e = checkContactDetails($id, $whmcs->get_req_var("password") ? true : false);
                $subaccount = $whmcs->get_req_var("subaccount");
                if (!$subaccount) {
                    $password = $permissions = "";
                }
                $smartyvalues["errormessage"] = $errormessage;
                if (!$errormessage) {
                    $oldcontactdata = get_query_vals("tblcontacts", "", array("userid" => $legacyClient->getID(), "id" => $id));
                    $array = db_build_update_array(array("firstname", "lastname", "companyname", "email", "address1", "address2", "city", "state", "postcode", "country", "phonenumber", "subaccount", "permissions", "generalemails", "productemails", "domainemails", "invoiceemails", "supportemails"), "implode");
                    if ($array["phonenumber"]) {
                        $array["phonenumber"] = $phonenumber = App::formatPostedPhoneNumber();
                    }
                    $array["subaccount"] = $subaccount ? "1" : "0";
                    $password = $whmcs->get_req_var("password");
                    if ($password) {
                        $hasher = new WHMCS\Security\Hash\Password();
                        $array["password"] = $hasher->hash(WHMCS\Input\Sanitize::decode($password));
                    }
                    update_query("tblcontacts", $array, array("userid" => $legacyClient->getID(), "id" => $id));
                    if (!$subaccount) {
                        WHMCS\Authentication\Remote\AccountLink::where("contact_id", "=", $id)->where("client_id", "=", $legacyClient->getID())->delete();
                    }
                    run_hook("ContactEdit", array_merge(array("userid" => $legacyClient->getID(), "contactid" => $id, "olddata" => $oldcontactdata), $array));
                    logActivity("Client Contact Modified - User ID: " . $legacyClient->getID() . " - Contact ID: " . $id);
                    $smartyvalues["successful"] = true;
                }
            }
            if ($whmcs->get_req_var("success")) {
                $smartyvalues["successful"] = true;
            }
            $contactsarray = $legacyClient->getContacts();
            if (!$id && count($contactsarray)) {
                $id = $contactsarray[0]["id"];
            }
            $smartyvalues["contacts"] = $contactsarray;
            $smartyvalues["contactid"] = $id;
            $remoteAuth = DI::make("remoteAuth");
            if ($id) {
                $contact = WHMCS\User\Client\Contact::find($id);
                $remoteAccountLinks = array();
                if ($contact) {
                    $smartyvalues["hasLinkedProvidersEnabled"] = (bool) count($remoteAuth->getProviders());
                    $linkUrl = routePath("auth-manage-client-links");
                    if (strpos($linkUrl, "?") !== false) {
                        $linkUrl .= "&cid=" . $id;
                    } else {
                        $linkUrl .= "?cid=" . $id;
                    }
                    $smartyvalues["linkedAccountsUrl"] = $linkUrl;
                    foreach ($contact->remoteAccountLinks()->get() as $remoteAccountLink) {
                        $provider = $remoteAuth->getProviderByName($remoteAccountLink->provider);
                        $remoteAccountLinks[$remoteAccountLink->id] = $provider->parseMetadata($remoteAccountLink->metadata);
                    }
                    $smartyvalues["remoteAccountLinks"] = $remoteAccountLinks;
                }
            }
            if (!$errormessage && $submit && $id || $id && !count($contact_data)) {
                $contact_data = $legacyClient->getContact($id);
                if (!$contact_data) {
                    redir("action=contacts", "clientarea.php");
                }
            }
            $smartyvalues["contactfirstname"] = $whmcs->get_req_var_if($e, "firstname", $contact_data);
            $smartyvalues["contactlastname"] = $whmcs->get_req_var_if($e, "lastname", $contact_data);
            $smartyvalues["contactcompanyname"] = $whmcs->get_req_var_if($e, "companyname", $contact_data);
            $smartyvalues["contactemail"] = $whmcs->get_req_var_if($e, "email", $contact_data);
            $smartyvalues["contactaddress1"] = $whmcs->get_req_var_if($e, "address1", $contact_data);
            $smartyvalues["contactaddress2"] = $whmcs->get_req_var_if($e, "address2", $contact_data);
            $smartyvalues["contactcity"] = $whmcs->get_req_var_if($e, "city", $contact_data);
            $smartyvalues["contactstate"] = $whmcs->get_req_var_if($e, "state", $contact_data);
            $smartyvalues["contactpostcode"] = $whmcs->get_req_var_if($e, "postcode", $contact_data);
            $smartyvalues["contactphonenumber"] = $whmcs->get_req_var_if($e, "phonenumber", $contact_data);
            $smartyvalues["countriesdropdown"] = getCountriesDropDown($whmcs->get_req_var_if($e, "country", $contact_data), "", "", false);
            $smartyvalues["subaccount"] = $whmcs->get_req_var_if($e, "subaccount", $contact_data);
            $permissions = $whmcs->get_req_var_if($e, "permissions", $contact_data);
            if ($permissions == "") {
                $permissions = array();
            }
            $smartyvalues["allPermissions"] = WHMCS\User\Client\Contact::$allPermissions;
            $smartyvalues["permissions"] = $permissions;
            $smartyvalues["generalemails"] = $whmcs->get_req_var_if($e, "generalemails", $contact_data);
            $smartyvalues["productemails"] = $whmcs->get_req_var_if($e, "productemails", $contact_data);
            $smartyvalues["domainemails"] = $whmcs->get_req_var_if($e, "domainemails", $contact_data);
            $smartyvalues["invoiceemails"] = $whmcs->get_req_var_if($e, "invoiceemails", $contact_data);
            $smartyvalues["supportemails"] = $whmcs->get_req_var_if($e, "supportemails", $contact_data);
            $ca->addOutputHookFunction("ClientAreaPageContacts");
        } else {
            if ($action == "addcontact") {
                checkContactPermission("contacts");
                $ca->setDisplayTitle(Lang::trans("clientareanavaddcontact"));
                $ca->setTemplate("clientareaaddcontact");
                $ca->addToBreadCrumb("clientarea.php?action=details", $whmcs->get_lang("clientareanavdetails"));
                $ca->addToBreadCrumb("clientarea.php?action=addcontact", $whmcs->get_lang("clientareanavaddcontact"));
                $firstname = $whmcs->get_req_var("firstname");
                $lastname = $whmcs->get_req_var("lastname");
                $companyname = $whmcs->get_req_var("companyname");
                $email = $whmcs->get_req_var("email");
                $address1 = $whmcs->get_req_var("address1");
                $address2 = $whmcs->get_req_var("address2");
                $city = $whmcs->get_req_var("city");
                $state = $whmcs->get_req_var("state");
                $postcode = $whmcs->get_req_var("postcode");
                $country = $whmcs->get_req_var("country");
                $phonenumber = $whmcs->get_req_var("phonenumber");
                $subaccount = $whmcs->get_req_var("subaccount");
                $permissions = $whmcs->get_req_var("permissions");
                $generalemails = $whmcs->get_req_var("generalemails");
                $productemails = $whmcs->get_req_var("productemails");
                $domainemails = $whmcs->get_req_var("domainemails");
                $invoiceemails = $whmcs->get_req_var("invoiceemails");
                $supportemails = $whmcs->get_req_var("supportemails");
                if ($submit) {
                    check_token();
                    $errormessage = checkContactDetails("", true);
                    if (!$subaccount) {
                        $password = $permissions = "";
                    }
                    $smartyvalues["errormessage"] = $errormessage;
                    if (!$errormessage) {
                        $contactid = addContact($legacyClient->getID(), $firstname, $lastname, $companyname, $email, $address1, $address2, $city, $state, $postcode, $country, $phonenumber, $password, $permissions, $generalemails, $productemails, $domainemails, $invoiceemails, $supportemails);
                        redir("action=contacts&id=" . $contactid . "&success=1");
                    }
                }
                $contactsarray = $legacyClient->getContacts();
                $smartyvalues["contacts"] = $contactsarray;
                if (!$permissions) {
                    $permissions = array();
                }
                $smartyvalues["contactfirstname"] = $firstname;
                $smartyvalues["contactlastname"] = $lastname;
                $smartyvalues["contactcompanyname"] = $companyname;
                $smartyvalues["contactemail"] = $email;
                $smartyvalues["contactaddress1"] = $address1;
                $smartyvalues["contactaddress2"] = $address2;
                $smartyvalues["contactcity"] = $city;
                $smartyvalues["contactstate"] = $state;
                $smartyvalues["contactpostcode"] = $postcode;
                $smartyvalues["contactphonenumber"] = $phonenumber;
                $smartyvalues["countriesdropdown"] = getCountriesDropDown($country, "", "", false);
                $smartyvalues["subaccount"] = $subaccount;
                $smartyvalues["allPermissions"] = WHMCS\User\Client\Contact::$allPermissions;
                $smartyvalues["permissions"] = $permissions;
                $smartyvalues["generalemails"] = $generalemails;
                $smartyvalues["productemails"] = $productemails;
                $smartyvalues["domainemails"] = $domainemails;
                $smartyvalues["invoiceemails"] = $invoiceemails;
                $smartyvalues["supportemails"] = $supportemails;
                $ca->addOutputHookFunction("ClientAreaPageAddContact");
            } else {
                if ($action == "creditcard") {
                    if (!CALinkUpdateCC()) {
                        redir();
                    }
                    checkContactPermission("invoices");
                    $ca->setDisplayTitle(Lang::trans("clientareanavccdetails"));
                    $ca->setTemplate("clientareacreditcard");
                    $ca->addToBreadCrumb("clientarea.php?action=creditcard", Lang::trans("clientareanavccdetails"));
                    $gateways = new WHMCS\Gateways();
                    $smartyvalues["remoteupdatecode"] = "";
                    $clientsGatewayModule = getClientsPaymentMethod($legacyClient->getID());
                    $gateway = new WHMCS\Module\Gateway();
                    if ($gateway->load($clientsGatewayModule)) {
                        if ($gateway->functionExists("credit_card_input")) {
                            $ca->assign("credit_card_input", $gateway->call("credit_card_input"));
                        }
                        if ($gateway->functionExists("remoteupdate")) {
                            $extraParams = array("gatewayid" => $ca->getClient()->paymentGatewayToken);
                            $remoteupdatecode = $gateway->call("remoteupdate", $extraParams);
                            if (!$remoteupdatecode) {
                                $remoteupdatecode = Lang::trans("creditcardupdatenotpossible");
                            }
                            $smartyvalues["remoteupdatecode"] = $remoteupdatecode;
                        } else {
                            if ($gateway->functionExists("remoteUpdateWithTemplate")) {
                                $ca->setTemplate(DIRECTORY_SEPARATOR . "modules" . DIRECTORY_SEPARATOR . "gateways" . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR . $gateway->getLoadedModule() . DIRECTORY_SEPARATOR . "creditcard.tpl");
                                $extraParams = array("gatewayid" => $ca->getClient()->paymentGatewayToken);
                                $remoteUpdateVariables = $gateway->call("remoteUpdateWithTemplate", $extraParams);
                                if (!$remoteUpdateVariables) {
                                    $remoteUpdateVariables = array("errorMessage" => Lang::trans("creditcardupdatenotpossible"));
                                }
                                foreach ($remoteUpdateVariables as $variable => $value) {
                                    $smartyvalues[$variable] = $value;
                                }
                            }
                        }
                    }
                    $cctype = $whmcs->get_req_var("cctype");
                    $ccnumber = $whmcs->get_req_var("ccnumber");
                    $ccexpirymonth = $whmcs->get_req_var("ccexpirymonth");
                    $ccexpiryyear = $whmcs->get_req_var("ccexpiryyear");
                    $ccstartmonth = $whmcs->get_req_var("ccstartmonth");
                    $ccstartyear = $whmcs->get_req_var("ccstartyear");
                    $ccissuenum = $whmcs->get_req_var("ccissuenum");
                    if ($submit) {
                        check_token();
                        $errormessage = updateCCDetails($legacyClient->getID(), $cctype, $ccnumber, $cardcvv, $ccexpirymonth . $ccexpiryyear, $ccstartmonth . $ccstartyear, $ccissuenum, "", "", $gateway->getLoadedModule());
                        if (!$errormessage) {
                            redir("action=creditcard&savesuccess=1");
                        }
                    }
                    if ($whmcs->get_req_var("remove") && $whmcs->get_config("CCAllowCustomerDelete")) {
                        check_token();
                        updateCCDetails($legacyClient->getID(), "", "", "", "", "", "", "", "", $gateway->getLoadedModule());
                        redir("action=creditcard&deletesuccess=1");
                    }
                    $smartyvalues["successful"] = (bool) $whmcs->get_req_var("savesuccess");
                    $smartyvalues["deletecc"] = (bool) $whmcs->get_req_var("deletesuccess");
                    if ($whmcs->get_req_var("deletesuccess")) {
                        $smartyvalues["successful"] = true;
                    }
                    $smartyvalues["errormessage"] = $errormessage;
                    $data = getCCDetails($legacyClient->getID());
                    $smartyvalues["cardtype"] = $data["cardtype"];
                    $smartyvalues["cardnum"] = $data["cardnum"];
                    $smartyvalues["cardlastfour"] = $data["cardlastfour"];
                    $smartyvalues["cardexp"] = $data["expdate"];
                    $smartyvalues["cardstart"] = $data["startdate"];
                    $smartyvalues["cardissuenum"] = $data["issuenumber"];
                    $acceptedcctypes = WHMCS\Config\Setting::getValue("AcceptedCardTypes");
                    $acceptedcctypes = explode(",", $acceptedcctypes);
                    $smartyvalues["acceptedcctypes"] = $acceptedcctypes;
                    $smartyvalues["showccissuestart"] = WHMCS\Config\Setting::getValue("ShowCCIssueStart");
                    $smartyvalues["allowcustomerdelete"] = WHMCS\Config\Setting::getValue("CCAllowCustomerDelete");
                    $smartyvalues["cctype"] = $cctype;
                    $smartyvalues["ccnumber"] = $ccnumber;
                    $smartyvalues["ccexpirymonth"] = $ccexpirymonth;
                    $smartyvalues["ccexpiryyear"] = $ccexpiryyear;
                    $smartyvalues["ccstartmonth"] = $ccstartmonth;
                    $smartyvalues["ccstartyear"] = $ccstartyear;
                    $smartyvalues["ccissuenum"] = $ccissuenum;
                    $smartyvalues["months"] = $gateways->getCCDateMonths();
                    $smartyvalues["startyears"] = $gateways->getCCStartDateYears();
                    $smartyvalues["years"] = $gateways->getCCExpiryDateYears();
                    $smartyvalues["expiryyears"] = $smartyvalues["years"];
                    $ca->addOutputHookFunction("ClientAreaPageCreditCard");
                } else {
                    if ($action == "changepw") {
                        $ca->setDisplayTitle(Lang::trans("clientareanavchangepw"));
                        $ca->setTemplate("clientareachangepw");
                        $ca->addToBreadCrumb("clientarea.php?action=details", $whmcs->get_lang("clientareanavdetails"));
                        $ca->addToBreadCrumb("clientarea.php?action=changepw", $whmcs->get_lang("clientareanavchangepw"));
                        $validate = new WHMCS\Validate();
                        if ($submit) {
                            check_token();
                            $existingpw = WHMCS\Input\Sanitize::decode($existingpw);
                            $newpw = WHMCS\Input\Sanitize::decode($newpw);
                            $confirmpw = WHMCS\Input\Sanitize::decode($confirmpw);
                            $userId = $legacyClient->getID();
                            $contactId = (int) WHMCS\Session::get("cid");
                            if ($contactId) {
                                $result = select_query("tblcontacts", "password", array("id" => $contactId, "userid" => $userId));
                            } else {
                                $result = select_query("tblclients", "password", array("id" => $userId));
                            }
                            $data = mysql_fetch_array($result);
                            $storedPasswordHash = $data["password"];
                            if ($validate->validate("password_verify", "existingpwd", "existingpasswordincorrect", array($existingpw, $storedPasswordHash)) && $validate->validate("required", "newpw", "ordererrorpassword") && $validate->validate("pwstrength", "newpw", "pwstrengthfail") && $validate->validate("required", "confirmpw", "clientareaerrorpasswordconfirm")) {
                                $validate->validate("match_value", "newpw", "clientareaerrorpasswordnotmatch", "confirmpw");
                            }
                            if (!$validate->hasErrors()) {
                                $hasher = new WHMCS\Security\Hash\Password();
                                $passwordToSave = $hasher->hash($newpw);
                                if ($contactId) {
                                    update_query("tblcontacts", array("password" => $passwordToSave), array("id" => $contactId, "userid" => $userId));
                                    run_hook("ContactChangePassword", array("userid" => $userId, "contactid" => $contactId, "password" => $newpw));
                                } else {
                                    update_query("tblclients", array("password" => $passwordToSave), array("id" => $userId));
                                    run_hook("ClientChangePassword", array("userid" => $userId, "password" => $newpw));
                                }
                                WHMCS\Session::set("upw", WHMCS\Authentication\Client::generateClientLoginHash($userId, $contactId, $passwordToSave));
                                logActivity("Modified Password - User ID: " . $legacyClient->getID() . (isset($_SESSION["cid"]) ? " - Contact ID: " . $_SESSION["cid"] : ""));
                                redir("action=changepw&success=1");
                            }
                        }
                        $smartyvalues["successful"] = $whmcs->get_req_var("success") ? true : false;
                        $smartyvalues["errormessage"] = $validate->getHTMLErrorOutput();
                        $ca->addOutputHookFunction("ClientAreaPageChangePassword");
                    } else {
                        if ($action == "security") {
                            $ca->setDisplayTitle(Lang::trans("clientareanavsecurity"));
                            $ca->setTemplate("clientareasecurity");
                            $ca->addToBreadCrumb("clientarea.php?action=details", $whmcs->get_lang("clientareanavdetails"));
                            $ca->addToBreadCrumb("clientarea.php?action=security", $whmcs->get_lang("clientareanavsecurity"));
                            if (!WHMCS\Session::get("cid")) {
                                if ($whmcs->get_req_var("toggle_sso")) {
                                    check_token();
                                    $client = $ca->getClient();
                                    $client->allowSso = (bool) $whmcs->get_req_var("allow_sso");
                                    $client->save();
                                    exit;
                                }
                                $smartyvalues["successful"] = $whmcs->get_req_var("successful") ? true : false;
                                $twofa = new WHMCS\TwoFactorAuthentication();
                                $twofa->setClientID($ca->getUserID());
                                if ($twofa->isActiveClients()) {
                                    $ca->assign("twofaavailable", true);
                                    $ca->assign("twofaactivation", "");
                                    if ($whmcs->get_req_var("2fasetup")) {
                                        if (!$twofa->isActiveClients()) {
                                            exit("Access denied");
                                        }
                                        $isAjaxRequest = !empty($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) == "xmlhttprequest";
                                        $requestedActionTitle = "";
                                        ob_start();
                                        if ($twofa->isEnabled()) {
                                            $disabled = $incorrect = false;
                                            if ($password = $whmcs->get_req_var("pwverify")) {
                                                $dbpwd = get_query_val("tblclients", "password", array("id" => $ca->getUserID()));
                                                $hasher = new WHMCS\Security\Hash\Password();
                                                if ($hasher->verify(WHMCS\Input\Sanitize::decode($password), $dbpwd)) {
                                                    $twofa->disableUser();
                                                    $disabled = true;
                                                } else {
                                                    $incorrect = true;
                                                }
                                            }
                                            $requestedActionTitle = $whmcs->get_lang("twofadisable");
                                            if (!$disabled) {
                                                echo "<p>" . $whmcs->get_lang("twofadisableintro") . "</p>";
                                                if ($incorrect) {
                                                    echo "<div class=\"errorbox alert alert-danger\"><strong>Password Incorrect</strong><br />Please try again...</div>";
                                                }
                                                echo "<form onsubmit=\"dialogSubmit();return false\" class=\"form-horizontal\">\n    <input type=\"hidden\" name=\"2fasetup\" value=\"1\" />\n    <div class=\"form-group\">\n        <label for=\"inputPasswordVerify\" class=\"col-sm-4 control-label\">" . $whmcs->get_lang("twofaconfirmpw") . "</label>\n        <div class=\"col-sm-6\">\n            <input type=\"password\" autocomplete=\"off\" name=\"pwverify\" id=\"inputPasswordVerify\" value=\"\" class=\"form-control\" />\n        </div>\n    </div>\n    <div class=\"form-group text-center\">\n        <input type=\"button\" value=\"" . $whmcs->get_lang("twofadisable") . "\" class=\"btn btn-warning\" onclick=\"dialogSubmit()\" />\n    </div>\n</form>";
                                            } else {
                                                echo "<p>" . $whmcs->get_lang("twofadisableconfirmation") . "</p>\n<form method=\"post\" action=\"clientarea.php?action=security\">\n    <p class=\"text-center\"><input type=\"submit\" value=\"" . $whmcs->get_lang("returnclient") . "\" class=\"btn btn-primary\" /></p>\n</form>";
                                            }
                                        } else {
                                            $modules = $twofa->getAvailableModules();
                                            if (isset($module) && in_array($module, $modules)) {
                                                $output = $twofa->moduleCall("activate", $module);
                                                if (is_array($output) && isset($output["completed"])) {
                                                    $msg = isset($output["msg"]) ? $output["msg"] : "";
                                                    $settings = isset($output["settings"]) ? $output["settings"] : array();
                                                    $backupcode = $twofa->activateUser($module, $settings);
                                                    $output = "";
                                                    if ($backupcode) {
                                                        $requestedActionTitle = $whmcs->get_lang("twofaactivationcomplete");
                                                        if ($msg) {
                                                            $output .= "<div class=\"alert alert-success text-center\">" . $msg . "</div>";
                                                        }
                                                        $output .= "<h2>" . $whmcs->get_lang("twofabackupcodeis") . ":</h2>\n<div style=\"margin:20px auto;padding:10px;width:300px;border:1px dashed #AE432E;color:#AE432E;text-align:center;font-size:1.6em;\">" . $backupcode . "</div>\n<p class=\"text-center\">" . $whmcs->get_lang("twofabackupcodeexpl") . "</p>\n<form method=\"post\" action=\"clientarea.php?action=security\">\n    <p class=\"text-center\"><input type=\"submit\" value=\"" . $whmcs->get_lang("returnclient") . "\" class=\"btn btn-success\" /></p>\n</form>";
                                                    } else {
                                                        $output = $whmcs->get_lang("twofaactivationerror");
                                                    }
                                                } else {
                                                    if ($output) {
                                                        $output = "<div class=\"textleft\">" . $output . "</div>";
                                                    }
                                                }
                                                if (!$output) {
                                                    echo $whmcs->get_lang("twofageneralerror");
                                                } else {
                                                    echo $output;
                                                }
                                            } else {
                                                $requestedActionTitle = $whmcs->get_lang("twofasetup");
                                                if ($whmcs->get_req_var("enforce")) {
                                                    echo "<br /><div class=\"errorbox alert alert-warning\">" . $whmcs->get_lang("twofaenforced") . "</div>";
                                                }
                                                echo "<p class=\"textleft\">" . $whmcs->get_lang("twofaactivationintro") . "</p>\n<form><input type=\"hidden\" name=\"2fasetup\" value=\"1\" />";
                                                if (1 < count($modules)) {
                                                    echo "<p>" . $whmcs->get_lang("twofaactivationmultichoice") . "</p>\n<div style=\"margin:0 auto;width:400px;\">";
                                                    $mod = new WHMCS\Module\Security();
                                                    $first = true;
                                                    foreach ($modules as $module) {
                                                        $mod->load($module);
                                                        $configarray = $mod->call("config");
                                                        echo "<label class=\"radio textleft\"><input type=\"radio\" name=\"module\" value=\"" . $module . "\"" . ($first ? " checked" : "") . " /> " . (isset($configarray["FriendlyName"]["Value"]) ? $configarray["FriendlyName"]["Value"] : ucfirst($module)) . "</label>";
                                                        $first = false;
                                                    }
                                                    echo "</div>";
                                                } else {
                                                    echo "<input type=\"hidden\" name=\"module\" value=\"" . $modules[0] . "\" />";
                                                }
                                                echo "<p align=\"center\"><br /><input type=\"button\" value=\"" . $whmcs->get_lang("twofasetupgetstarted") . " &raquo;\" onclick=\"dialogSubmit()\" class=\"btn btn-primary\" /></form>";
                                            }
                                        }
                                        $content = ob_get_contents();
                                        ob_end_clean();
                                        if ($isAjaxRequest) {
                                            echo json_encode(array("title" => $requestedActionTitle, "body" => $content));
                                            throw new WHMCS\Exception\ProgramExit();
                                        }
                                        $content = "<h2>" . $requestedActionTitle . "</h2>" . $content;
                                        $ca->assign("twofaactivation", $content);
                                    }
                                    $ca->assign("twofastatus", $twofa->isEnabled());
                                }
                                if (App::getFromRequest("activate2fa")) {
                                    add_hook("ClientAreaFooterOutput", 1, function () {
                                        return "<script>\n    jQuery(document).ready(function() {\n        jQuery('input[name=2fasetup]').parent('form').append('<input type=\"hidden\" name=\"enforce\" value=\"1\" />').submit();\n    });\n</script>";
                                    });
                                }
                                $securityquestions = getSecurityQuestions("");
                                $smartyvalues["securityquestions"] = $securityquestions;
                                $smartyvalues["securityquestionsenabled"] = count($securityquestions) ? true : false;
                                $clientsdetails = getClientsDetails($legacyClient->getID());
                                if ($clientsdetails["securityqid"] == 0) {
                                    $smartyvalues["nocurrent"] = true;
                                } else {
                                    foreach ($securityquestions as $values) {
                                        if ($values["id"] == $clientsdetails["securityqid"]) {
                                            $smartyvalues["currentquestion"] = $values["question"];
                                        }
                                    }
                                }
                                if ($whmcs->get_req_var("submit")) {
                                    check_token();
                                    if ($clientsdetails["securityqid"] && $clientsdetails["securityqans"] != $currentsecurityqans) {
                                        $errormessage .= "<li>" . Lang::trans("securitycurrentincorrect");
                                    }
                                    if (!$securityqans) {
                                        $errormessage .= "<li>" . Lang::trans("securityanswerrequired");
                                    }
                                    if ($securityqans != $securityqans2) {
                                        $errormessage .= "<li>" . Lang::trans("securitybothnotmatch");
                                    }
                                    if (!$errormessage) {
                                        update_query("tblclients", array("securityqid" => $securityqid, "securityqans" => encrypt($securityqans)), array("id" => $legacyClient->getID()));
                                        logActivity("Modified Security Question - User ID: " . $legacyClient->getID());
                                        redir("action=changesq&successful=true");
                                    }
                                }
                                $smartyvalues["errormessage"] = $errormessage;
                                $smartyvalues["showSsoSetting"] = 1 <= WHMCS\ApplicationLink\ApplicationLink::whereIsEnabled(1)->count();
                                $smartyvalues["isSsoEnabled"] = $ca->getClient()->allowSso;
                            } else {
                                $smartyvalues["twofaavailable"] = false;
                                $smartyvalues["twofaactivation"] = true;
                                $smartyvalues["securityquestionsenabled"] = false;
                                $smartyvalues["showSsoSetting"] = false;
                            }
                            $remoteAuthData = (new WHMCS\Authentication\Remote\Management\Client\ViewHelper())->getTemplateData(WHMCS\Authentication\Remote\Providers\AbstractRemoteAuthProvider::HTML_TARGET_CONNECT);
                            foreach ($remoteAuthData as $key => $value) {
                                $smartyvalues[$key] = $value;
                            }
                            $ca->addOutputHookFunction("ClientAreaPageSecurity");
                        } else {
                            if (in_array($action, array("hosting", "products", "services"))) {
                                checkContactPermission("products");
                                $ca->setDisplayTitle(Lang::trans("clientareaproducts"));
                                $ca->setTemplate("clientareaproducts");
                                $table = "tblhosting";
                                $fields = "COUNT(*)";
                                $where = "userid='" . db_escape_string($legacyClient->getID()) . "'";
                                if ($q) {
                                    $q = preg_replace("/[^a-z0-9-.]/", "", strtolower($q));
                                    $where .= " AND domain LIKE '%" . db_escape_string($q) . "%'";
                                    $smartyvalues["q"] = $q;
                                }
                                if ($module = $whmcs->get_req_var("module")) {
                                    $where .= " AND tblproducts.servertype='" . db_escape_string($module) . "'";
                                }
                                $innerjoin = "tblproducts ON tblproducts.id=tblhosting.packageid INNER JOIN tblproductgroups ON tblproductgroups.id=tblproducts.gid";
                                $result = select_query($table, $fields, $where, "", "", "", $innerjoin);
                                $data = mysql_fetch_array($result);
                                $numitems = $data[0];
                                list($orderby, $sort, $limit) = clientAreaTableInit("prod", "product", "ASC", $numitems);
                                $smartyvalues["orderby"] = $orderby;
                                $smartyvalues["sort"] = strtolower($sort);
                                if ($orderby == "price") {
                                    $orderby = "amount";
                                } else {
                                    if ($orderby == "billingcycle") {
                                        $orderby = "billingcycle";
                                    } else {
                                        if ($orderby == "nextduedate") {
                                            $orderby = "nextduedate";
                                        } else {
                                            if ($orderby == "status") {
                                                $orderby = "domainstatus";
                                            } else {
                                                $orderby = "domain` " . $sort . ",`tblproducts`.`name";
                                            }
                                        }
                                    }
                                }
                                $accounts = array();
                                $fields = "tblhosting.*,tblproductgroups.id AS group_id,tblproducts.name as product_name,tblproducts.tax," . "tblproductgroups.name as group_name,tblproducts.servertype";
                                $result = select_query($table, $fields, $where, $orderby, $sort, $limit, $innerjoin);
                                while ($data = mysql_fetch_array($result)) {
                                    $id = $data["id"];
                                    $productId = $data["packageid"];
                                    $regdate = $data["regdate"];
                                    $domain = $data["domain"];
                                    $firstpaymentamount = $data["firstpaymentamount"];
                                    $recurringamount = $data["amount"];
                                    $nextduedate = $data["nextduedate"];
                                    $billingcycle = $data["billingcycle"];
                                    $status = $data["domainstatus"];
                                    $downloads = WHMCS\Product\Product::find($productId)->getDownloadIds();
                                    $upgradepackages = WHMCS\Product\Product::find($productId)->getUpgradeProductIds();
                                    $productgroup = WHMCS\Product\Group::getGroupName($data["group_id"], $data["group_name"]);
                                    $productname = WHMCS\Product\Product::getProductName($data["packageid"], $data["product_name"]);
                                    $tax = $data["tax"];
                                    $server = $data["server"];
                                    $username = $data["username"];
                                    $module = $data["servertype"];
                                    $downloadscount = 0;
                                    if (is_array($downloads)) {
                                        foreach ($downloads as $dl) {
                                            if (trim($dl)) {
                                                $downloadscount++;
                                            }
                                        }
                                    }
                                    $serverarray = array();
                                    if ($server) {
                                        $result2 = select_query("tblservers", "", array("id" => $server));
                                        $serverarray = mysql_fetch_array($result2);
                                    }
                                    if ($tax) {
                                    }
                                    $normalisedRegDate = $regdate;
                                    $regdate = fromMySQLDate($regdate, 0, 1, "-");
                                    $normalisedNextDueDate = $nextduedate;
                                    $nextduedate = fromMySQLDate($nextduedate, 0, 1, "-");
                                    $langbillingcycle = $ca->getRawStatus($billingcycle);
                                    $rawstatus = $ca->getRawStatus($status);
                                    $legacyClassTplVar = $status;
                                    if (!in_array($legacyClassTplVar, array("Active", "Completed", "Pending", "Suspended"))) {
                                        $legacyClassTplVar = "Terminated";
                                    }
                                    $amount = $billingcycle == "One Time" ? $firstpaymentamount : $recurringamount;
                                    $accounts[] = array("id" => $id, "regdate" => $regdate, "normalisedRegDate" => $normalisedRegDate, "group" => $productgroup, "product" => $productname, "module" => $module, "server" => $serverarray, "domain" => $domain, "firstpaymentamount" => formatCurrency($firstpaymentamount), "recurringamount" => formatCurrency($recurringamount), "amountnum" => $amount, "amount" => formatCurrency($amount), "nextduedate" => $nextduedate, "normalisedNextDueDate" => $normalisedNextDueDate, "billingcycle" => Lang::trans("orderpaymentterm" . $langbillingcycle), "username" => $username, "status" => $status, "statusClass" => WHMCS\View\Helper::generateCssFriendlyClassName($status), "statustext" => Lang::trans("clientarea" . $rawstatus), "rawstatus" => $rawstatus, "class" => strtolower($legacyClassTplVar), "addons" => get_query_val("tblhostingaddons", "id", array("hostingid" => $id), "id", "DESC") ? "1" : "", "packagesupgrade" => $upgradepackages ? "1" : "", "downloads" => $downloads ? "1" : "", "showcancelbutton" => WHMCS\Config\Setting::getValue("ShowCancellationButton"));
                                }
                                $ca->assign("services", $accounts);
                                $smartyvalues = array_merge($smartyvalues, clientAreaTablePageNav($numitems));
                                $ca->addOutputHookFunction("ClientAreaPageProductsServices");
                            } else {
                                if ($action == "productdetails") {
                                    checkContactPermission("products");
                                    $ca->setDisplayTitle(Lang::trans("manageproduct"));
                                    $ca->setTemplate("clientareaproductdetails");
                                    $service = new WHMCS\Service($id, $legacyClient->getID());
                                    if ($service->isNotValid()) {
                                        redir("action=products", "clientarea.php");
                                    }
                                    $serviceModel = WHMCS\Service\Service::find($service->getID());
                                    $ca->addToBreadCrumb("clientarea.php?action=products", $whmcs->get_lang("clientareaproducts"));
                                    $ca->addToBreadCrumb("clientarea.php?action=productdetails#", $whmcs->get_lang("clientareaproductdetails"));
                                    $customfields = $service->getCustomFields();
                                    $domainIds = WHMCS\Domain\Domain::where("userid", $legacyClient->getID())->where("domain", $service->getData("domain"))->where("status", "Active")->pluck("id")->all();
                                    if (count($domainIds) < 1) {
                                        $domainIds = WHMCS\Domain\Domain::where("userid", $legacyClient->getID())->where("domain", $service->getData("domain"))->where("status", "!=", "Fraud")->pluck("id")->all();
                                    }
                                    if (count($domainIds) < 1) {
                                        $domainIds = WHMCS\Domain\Domain::where("userid", $legacyClient->getID())->where("domain", $service->getData("domain"))->where("status", "Fraud")->pluck("id")->all();
                                    }
                                    if (count($domainIds) < 1) {
                                        $domainId = "";
                                    } else {
                                        $domainId = array_shift($domainIds);
                                    }
                                    $ca->assign("id", $service->getData("id"));
                                    $ca->assign("domainId", $domainId);
                                    $ca->assign("serviceid", $service->getData("id"));
                                    $ca->assign("pid", $service->getData("packageid"));
                                    $ca->assign("producttype", $service->getData("type"));
                                    $ca->assign("type", $service->getData("type"));
                                    $ca->assign("regdate", fromMySQLDate($service->getData("regdate"), 0, 1, "-"));
                                    $ca->assign("modulename", $service->getModule());
                                    $ca->assign("module", $service->getModule());
                                    $ca->assign("serverdata", $service->getServerInfo());
                                    $ca->assign("domain", $service->getData("domain"));
                                    $ca->assign("groupname", $service->getData("groupname"));
                                    $ca->assign("product", $service->getData("productname"));
                                    $ca->assign("paymentmethod", $service->getPaymentMethod());
                                    $ca->assign("firstpaymentamount", formatCurrency($service->getData("firstpaymentamount")));
                                    $ca->assign("recurringamount", formatCurrency($service->getData("amount")));
                                    $ca->assign("billingcycle", $service->getBillingCycleDisplay());
                                    $ca->assign("nextduedate", fromMySQLDate($service->getData("nextduedate"), 0, 1, "-"));
                                    $ca->assign("systemStatus", $service->getData("status"));
                                    $ca->assign("status", $service->getStatusDisplay());
                                    $ca->assign("rawstatus", strtolower($service->getData("status")));
                                    $ca->assign("dedicatedip", $service->getData("dedicatedip"));
                                    $ca->assign("assignedips", $service->getData("assignedips"));
                                    $ca->assign("ns1", $service->getData("ns1"));
                                    $ca->assign("ns2", $service->getData("ns2"));
                                    $ca->assign("packagesupgrade", $service->getAllowProductUpgrades());
                                    $ca->assign("configoptionsupgrade", $service->getAllowConfigOptionsUpgrade());
                                    $ca->assign("customfields", $customfields);
                                    $ca->assign("productcustomfields", $customfields);
                                    $ca->assign("suspendreason", $service->getSuspensionReason());
                                    $ca->assign("subscriptionid", $service->getData("subscriptionid"));
                                    $diskstats = $service->getDiskUsageStats();
                                    foreach ($diskstats as $k => $v) {
                                        $ca->assign($k, $v);
                                    }
                                    $availableAddonIds = array();
                                    $availableAddonProducts = array();
                                    if ($service->getData("status") == "Active") {
                                        $predefinedAddonProducts = $service->getPredefinedAddonsOnce();
                                        $availableAddonIds = $service->hasProductGotAddons();
                                        foreach ($availableAddonIds as $addonId) {
                                            $availableAddonProducts[$addonId] = $predefinedAddonProducts[$addonId];
                                        }
                                    }
                                    $ca->assign("showcancelbutton", $service->getAllowCancellation());
                                    $ca->assign("configurableoptions", $service->getConfigurableOptions());
                                    $ca->assign("addons", $service->getAddons());
                                    $ca->assign("addonsavailable", $availableAddonIds);
                                    $ca->assign("availableAddonProducts", $availableAddonProducts);
                                    $ca->assign("downloads", $service->getAssociatedDownloads());
                                    $ca->assign("pendingcancellation", $service->hasCancellationRequest());
                                    $ca->assign("username", $service->getData("username"));
                                    $ca->assign("password", $service->getData("password"));
                                    $hookResponses = run_hook("ClientAreaProductDetailsOutput", array("service" => $serviceModel));
                                    $ca->assign("hookOutput", $hookResponses);
                                    $hookResponses = run_hook("ClientAreaProductDetailsPreModuleTemplate", $ca->getTemplateVariables());
                                    foreach ($hookResponses as $hookTemplateVariables) {
                                        foreach ($hookTemplateVariables as $k => $v) {
                                            $ca->assign($k, $v);
                                        }
                                    }
                                    $tplOverviewTabOutput = "";
                                    $moduleClientAreaOutput = "";
                                    $clientAreaCustomButtons = array();
                                    $ca->assign("modulecustombuttonresult", "");
                                    if (App::isInRequest("addonId") && App::getFromRequest("modop") == "custom") {
                                        $service = new WHMCS\Addon();
                                        $service->setAddonId(App::getFromRequest("addonId"));
                                    }
                                    if ($service->getModule()) {
                                        $moduleInterface = new WHMCS\Module\Server();
                                        if ($service instanceof WHMCS\Addon) {
                                            $moduleInterface->loadByAddonId($service->getID());
                                        } else {
                                            $moduleInterface->loadByServiceID($service->getID());
                                        }
                                        if ($whmcs->get_req_var("dosinglesignon") && checkContactPermission("productsso", true)) {
                                            if ($service->getData("status") == "Active") {
                                                try {
                                                    $redirectUrl = $moduleInterface->getSingleSignOnUrlForService();
                                                    header("Location: " . $redirectUrl);
                                                    exit;
                                                } catch (WHMCS\Exception\Module\SingleSignOnError $e) {
                                                    $ca->assign("modulecustombuttonresult", $whmcs->get_lang("ssounabletologin"));
                                                } catch (Exception $e) {
                                                    logActivity("Single Sign-On Request Failed with a Fatal Error: " . $e->getMessage());
                                                    $ca->assign("modulecustombuttonresult", $whmcs->get_lang("ssofatalerror"));
                                                }
                                            } else {
                                                $ca->assign("modulecustombuttonresult", Lang::trans("productMustBeActiveForModuleCmds"));
                                            }
                                        } else {
                                            if ($whmcs->get_req_var("dosinglesignon")) {
                                                $ca->assign("modulecustombuttonresult", Lang::trans("subaccountSsoDenied"));
                                            }
                                        }
                                        $moduleFolderPath = $moduleInterface->getBaseModuleDir() . DIRECTORY_SEPARATOR . $service->getModule();
                                        $moduleFolderPath = substr($moduleFolderPath, strlen(ROOTDIR));
                                        $allowedModuleFunctions = array();
                                        $success = $service->moduleCall("ClientAreaAllowedFunctions");
                                        if ($success) {
                                            $clientAreaAllowedFunctions = $service->getModuleReturn("data");
                                            if (is_array($clientAreaAllowedFunctions)) {
                                                foreach ($clientAreaAllowedFunctions as $functionName) {
                                                    if (is_string($functionName)) {
                                                        $allowedModuleFunctions[] = $functionName;
                                                    }
                                                }
                                            }
                                        }
                                        $success = $service->moduleCall("ClientAreaCustomButtonArray");
                                        if ($success) {
                                            $clientAreaCustomButtons = $service->getModuleReturn("data");
                                            if (is_array($clientAreaCustomButtons)) {
                                                foreach ($clientAreaCustomButtons as $buttonLabel => $functionName) {
                                                    if (is_string($functionName)) {
                                                        $allowedModuleFunctions[] = $functionName;
                                                    }
                                                }
                                            }
                                        }
                                        $moduleOperation = $whmcs->get_req_var("modop");
                                        $moduleAction = $whmcs->get_req_var("a");
                                        if ($serverAction = $whmcs->get_req_var("serveraction")) {
                                            $moduleOperation = $serverAction;
                                        }
                                        if ($moduleOperation == "custom" && in_array($moduleAction, $allowedModuleFunctions)) {
                                            if ($service->getData("status") == "Active") {
                                                checkContactPermission("manageproducts");
                                                $success = $service->moduleCall($moduleAction);
                                                if ($success) {
                                                    $data = $service->getModuleReturn("data");
                                                    if (is_array($data)) {
                                                        if (isset($data["jsonResponse"])) {
                                                            $response = new WHMCS\Http\JsonResponse();
                                                            $response->setData($data["jsonResponse"]);
                                                            $response->send();
                                                            exit;
                                                        }
                                                        if (isset($data["overrideDisplayTitle"])) {
                                                            $ca->setDisplayTitle($data["overrideDisplayTitle"]);
                                                        }
                                                        if (isset($data["overrideBreadcrumb"]) && is_array($data["overrideBreadcrumb"])) {
                                                            $ca->resetBreadCrumb()->addToBreadCrumb("index.php", $whmcs->get_lang("globalsystemname"))->addToBreadCrumb("clientarea.php", $whmcs->get_lang("clientareatitle"));
                                                            foreach ($data["overrideBreadcrumb"] as $breadcrumb) {
                                                                $ca->addToBreadCrumb($breadcrumb[0], $breadcrumb[1]);
                                                            }
                                                        }
                                                        if (isset($data["appendToBreadcrumb"]) && is_array($data["appendToBreadcrumb"])) {
                                                            foreach ($data["appendToBreadcrumb"] as $breadcrumb) {
                                                                $ca->addToBreadCrumb($breadcrumb[0], $breadcrumb[1]);
                                                            }
                                                        }
                                                        if (isset($data["outputTemplateFile"])) {
                                                            $ca->setTemplate($moduleInterface->findTemplate($data["outputTemplateFile"]));
                                                        } else {
                                                            if (isset($data["templatefile"])) {
                                                                $ca->setTemplate($moduleInterface->findTemplate($data["templatefile"] . ".tpl"));
                                                            }
                                                        }
                                                        if (isset($data["breadcrumb"]) && is_array($data["breadcrumb"])) {
                                                            foreach ($data["breadcrumb"] as $href => $label) {
                                                                $ca->addToBreadCrumb($href, $label);
                                                            }
                                                        }
                                                        if (is_array($data["templateVariables"]) || is_array($data["vars"])) {
                                                            $templateVars = isset($data["templateVariables"]) ? $data["templateVariables"] : $data["vars"];
                                                            foreach ($templateVars as $key => $value) {
                                                                $ca->assign($key, $value);
                                                            }
                                                        }
                                                    } else {
                                                        $ca->assign("modulecustombuttonresult", "success");
                                                    }
                                                } else {
                                                    $ca->assign("modulecustombuttonresult", $service->getLastError());
                                                }
                                            } else {
                                                $ca->assign("modulecustombuttonresult", Lang::trans("productMustBeActiveForModuleCmds"));
                                            }
                                        }
                                        $smartyvalues["modulechangepwresult"] = "";
                                        if ($service->getData("status") == "Active" && $service->hasFunction("ChangePassword") && $service->getAllowChangePassword()) {
                                            $ca->assign("serverchangepassword", true);
                                            $ca->assign("modulechangepassword", true);
                                            $modulechangepasswordmessage = "";
                                            $modulechangepassword = $whmcs->get_req_var("modulechangepassword");
                                            if ($whmcs->get_req_var("serverchangepassword")) {
                                                $modulechangepassword = true;
                                            }
                                            if ($modulechangepassword) {
                                                check_token();
                                                checkContactPermission("manageproducts");
                                                $newpwfield = "newpw";
                                                $newpassword1 = $whmcs->get_req_var("newpw");
                                                $newpassword2 = $whmcs->get_req_var("confirmpw");
                                                foreach (array("newpassword1", "newserverpassword1") as $key) {
                                                    if (!$newpassword1 && $whmcs->get_req_var($key)) {
                                                        $newpwfield = $key;
                                                        $newpassword1 = $whmcs->get_req_var($key);
                                                    }
                                                }
                                                foreach (array("newpassword2", "newserverpassword2") as $key) {
                                                    if ($whmcs->get_req_var($key)) {
                                                        $newpassword2 = $whmcs->get_req_var($key);
                                                    }
                                                }
                                                $validate = new WHMCS\Validate();
                                                if ($validate->validate("match_value", "newpw", "clientareaerrorpasswordnotmatch", array($newpassword1, $newpassword2))) {
                                                    $validate->validate("pwstrength", $newpwfield, "pwstrengthfail");
                                                }
                                                if ($validate->hasErrors()) {
                                                    $modulechangepwresult = "error";
                                                    $modulechangepasswordmessage = $validate->getHTMLErrorOutput();
                                                } else {
                                                    update_query("tblhosting", array("password" => encrypt($newpassword1)), array("id" => $id));
                                                    $updatearr = array("password" => WHMCS\Input\Sanitize::decode($newpassword1));
                                                    $success = $service->moduleCall("ChangePassword", $updatearr);
                                                    if ($success) {
                                                        logActivity("Module Change Password Successful - Service ID: " . $id);
                                                        run_hook("AfterModuleChangePassword", array("serviceid" => $id, "oldpassword" => $service->getData("password"), "newpassword" => $updatearr["password"]));
                                                        $modulechangepwresult = "success";
                                                        $modulechangepasswordmessage = Lang::trans("serverchangepasswordsuccessful");
                                                        $ca->assign("password", $newpassword1);
                                                    } else {
                                                        $modulechangepwresult = "error";
                                                        $modulechangepasswordmessage = Lang::trans("serverchangepasswordfailed");
                                                        update_query("tblhosting", array("password" => encrypt($service->getData("password"))), array("id" => $id));
                                                    }
                                                }
                                                $smartyvalues["modulechangepwresult"] = $modulechangepwresult;
                                                $smartyvalues["modulechangepasswordmessage"] = $modulechangepasswordmessage;
                                            }
                                        }
                                        $customTemplateVariables = $ca->getTemplateVariables();
                                        $customTemplateVariables["moduleParams"] = $moduleInterface->buildParams();
                                        $moduleTemplateVariables = array();
                                        $tabOverviewModuleDirectOutputContent = "";
                                        $tabOverviewModuleOutputTemplate = "";
                                        $tabOverviewReplacementTemplate = "";
                                        if ($service->hasFunction("ClientArea")) {
                                            $inputParams = array("clientareatemplate" => App::getClientAreaTemplate()->getName(), "templatevars" => $customTemplateVariables, "whmcsVersion" => App::getVersion()->getCanonical());
                                            $success = $service->moduleCall("ClientArea", $inputParams);
                                            $data = $service->getModuleReturn("data");
                                            if (is_array($data)) {
                                                if (isset($data["overrideDisplayTitle"])) {
                                                    $ca->setDisplayTitle($data["overrideDisplayTitle"]);
                                                }
                                                if (isset($data["overrideBreadcrumb"]) && is_array($data["overrideBreadcrumb"])) {
                                                    $ca->resetBreadCrumb()->addToBreadCrumb("index.php", $whmcs->get_lang("globalsystemname"))->addToBreadCrumb("clientarea.php", $whmcs->get_lang("clientareatitle"));
                                                    foreach ($data["overrideBreadcrumb"] as $breadcrumb) {
                                                        $ca->addToBreadCrumb($breadcrumb[0], $breadcrumb[1]);
                                                    }
                                                }
                                                if (isset($data["appendToBreadcrumb"]) && is_array($data["appendToBreadcrumb"])) {
                                                    foreach ($data["appendToBreadcrumb"] as $breadcrumb) {
                                                        $ca->addToBreadCrumb($breadcrumb[0], $breadcrumb[1]);
                                                    }
                                                }
                                                if (isset($data["tabOverviewModuleOutputTemplate"])) {
                                                    $tabOverviewModuleOutputTemplate = $moduleInterface->findTemplate($data["tabOverviewModuleOutputTemplate"]);
                                                } else {
                                                    if (isset($data["templatefile"])) {
                                                        $tabOverviewModuleOutputTemplate = $moduleInterface->findTemplate($data["templatefile"]);
                                                    }
                                                }
                                                if (isset($data["tabOverviewReplacementTemplate"])) {
                                                    $tabOverviewReplacementTemplate = $moduleInterface->findTemplate($data["tabOverviewReplacementTemplate"]);
                                                }
                                                if (isset($data["templateVariables"]) && is_array($data["templateVariables"])) {
                                                    $moduleTemplateVariables = $data["templateVariables"];
                                                } else {
                                                    if (isset($data["vars"]) && is_array($data["vars"])) {
                                                        $moduleTemplateVariables = $data["vars"];
                                                    }
                                                }
                                            } else {
                                                $tabOverviewModuleDirectOutputContent = $data != WHMCS\Module\Server::FUNCTIONDOESNTEXIST ? $data : "";
                                            }
                                        }
                                        if ($service->getData("status") == "Active" && checkContactPermission("manageproducts", true)) {
                                            if ($tabOverviewModuleOutputTemplate) {
                                                if (file_exists(ROOTDIR . $tabOverviewModuleOutputTemplate)) {
                                                    $moduleClientAreaOutput = $ca->getSingleTPLOutput($tabOverviewModuleOutputTemplate, $moduleInterface->prepareParams(array_merge($customTemplateVariables, $customTemplateVariables["moduleParams"], $moduleTemplateVariables)));
                                                } else {
                                                    $moduleClientAreaOutput = "Template File \"" . WHMCS\Input\Sanitize::makeSafeForOutput($tabOverviewModuleOutputTemplate) . "\" Not Found";
                                                }
                                            } else {
                                                if ($tabOverviewModuleDirectOutputContent) {
                                                    $tabOverviewModuleOutputTemplate = "";
                                                    $moduleClientAreaOutput = $tabOverviewModuleDirectOutputContent;
                                                } else {
                                                    if (file_exists(ROOTDIR . $moduleFolderPath . DIRECTORY_SEPARATOR . "clientarea.tpl")) {
                                                        $tplPath = $moduleFolderPath . DIRECTORY_SEPARATOR . "clientarea.tpl";
                                                        $moduleClientAreaOutput = $ca->getSingleTPLOutput($tplPath, $moduleInterface->prepareParams(array_merge($customTemplateVariables, $customTemplateVariables["moduleParams"], $moduleTemplateVariables)));
                                                    }
                                                }
                                            }
                                        }
                                        if ($tabOverviewReplacementTemplate) {
                                            if (file_exists(ROOTDIR . $tabOverviewReplacementTemplate)) {
                                                $tplOverviewTabOutput = $ca->getSingleTPLOutput($tabOverviewReplacementTemplate, $moduleInterface->prepareParams(array_merge($customTemplateVariables, $moduleTemplateVariables)));
                                            } else {
                                                $tplOverviewTabOutput = "Template File \"" . WHMCS\Input\Sanitize::makeSafeForOutput($tabOverviewReplacementTemplate) . "\" Not Found";
                                            }
                                        }
                                    }
                                    $ca->assign("tplOverviewTabOutput", $tplOverviewTabOutput);
                                    $ca->assign("modulecustombuttons", $clientAreaCustomButtons);
                                    $ca->assign("servercustombuttons", $clientAreaCustomButtons);
                                    $ca->assign("moduleclientarea", $moduleClientAreaOutput);
                                    $ca->assign("serverclientarea", $moduleClientAreaOutput);
                                    run_hook("ClientAreaProductDetails", array("service" => $serviceModel));
                                    $ca->addOutputHookFunction("ClientAreaPageProductDetails");
                                } else {
                                    if ($action == "domains") {
                                        checkContactPermission("domains");
                                        $ca->setDisplayTitle(Lang::trans("clientareanavdomains"));
                                        $ca->setTemplate("clientareadomains");
                                        $warnings = "";
                                        if (isset($error)) {
                                            if ($error == "noDomainsSelected") {
                                                $warnings .= Lang::trans("actionRequiresAtLeastOneDomainSelected");
                                            }
                                            if ($error == "nonActiveDomainsSelected") {
                                                $warnings .= Lang::trans("domainCannotBeManagedUnlessActive");
                                            }
                                        }
                                        $where = "userid='" . db_escape_string($legacyClient->getID()) . "'";
                                        if ($q) {
                                            $q = preg_replace("/[^a-z0-9-.]/", "", strtolower($q));
                                            $where .= " AND domain LIKE '%" . db_escape_string($q) . "%'";
                                            $smartyvalues["q"] = $q;
                                        }
                                        $result = select_query("tbldomains", "COUNT(*)", $where);
                                        $data = mysql_fetch_array($result);
                                        $numitems = $data[0];
                                        list($orderby, $sort, $limit) = clientAreaTableInit("dom", "domain", "ASC", $numitems);
                                        $smartyvalues["orderby"] = $orderby;
                                        $smartyvalues["sort"] = strtolower($sort);
                                        if ($orderby == "price") {
                                            $orderby = "recurringamount";
                                        } else {
                                            if ($orderby == "regdate") {
                                                $orderby = "registrationdate";
                                            } else {
                                                if ($orderby == "nextduedate") {
                                                    $orderby = "nextduedate";
                                                } else {
                                                    if ($orderby == "status") {
                                                        $orderby = "status";
                                                    } else {
                                                        if ($orderby == "autorenew") {
                                                            $orderby = "donotrenew";
                                                        } else {
                                                            $orderby = "domain";
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        $storedDomains = array();
                                        $result = select_query("tbldomains", "*, (DATEDIFF(expirydate, now())) as days_until_expiry", $where, $orderby, $sort, $limit);
                                        while ($data = mysql_fetch_array($result)) {
                                            $id = $data["id"];
                                            $listDomain = new WHMCS\Domains();
                                            $domainData = $listDomain->getDomainsDatabyID($id);
                                            $manageNS = false;
                                            if ($listDomain->hasFunction("SaveNameservers")) {
                                                $manageNS = true;
                                            }
                                            $registrationdate = $data["registrationdate"];
                                            $domain = $data["domain"];
                                            $amount = $data["recurringamount"];
                                            $nextduedate = $data["nextduedate"];
                                            $expirydate = $data["expirydate"];
                                            $daysUntilExpiry = $data["days_until_expiry"];
                                            $status = $data["status"];
                                            $donotrenew = $data["donotrenew"];
                                            $next30 = $next90 = $next180 = $after180 = false;
                                            if (0 <= $daysUntilExpiry && $status == "Active") {
                                                switch (true) {
                                                    case $daysUntilExpiry <= 30:
                                                        $next30 = true;
                                                        break;
                                                    case $daysUntilExpiry <= 90:
                                                        $next90 = true;
                                                        break;
                                                    case $daysUntilExpiry <= 180:
                                                        $next180 = true;
                                                        break;
                                                    default:
                                                        $after180 = true;
                                                }
                                            }
                                            $rawstatus = $ca->getRawStatus($status);
                                            $autorenew = $donotrenew ? false : true;
                                            $normalisedRegistrationDate = $registrationdate;
                                            $registrationdate = fromMySQLDate($registrationdate, 0, 1, "-");
                                            $normalisedNextDueDate = $nextduedate;
                                            $nextduedate = fromMySQLDate($nextduedate, 0, 1, "-");
                                            $normalisedExpiryDate = $expirydate;
                                            $expirydate = fromMySQLDate($expirydate, 0, 1, "-");
                                            $storedDomains[] = array("id" => $id, "domain" => $domain, "amount" => formatCurrency($amount), "registrationdate" => $registrationdate, "normalisedRegistrationDate" => $normalisedRegistrationDate, "nextduedate" => $nextduedate, "normalisedNextDueDate" => $normalisedNextDueDate, "expirydate" => $expirydate, "normalisedExpiryDate" => $normalisedExpiryDate, "daysUntilExpiry" => $daysUntilExpiry, "status" => $status, "statusClass" => WHMCS\View\Helper::generateCssFriendlyClassName($status), "rawstatus" => $rawstatus, "statustext" => Lang::trans("clientarea" . $rawstatus), "autorenew" => $autorenew, "next30" => $next30, "next90" => $next90, "next180" => $next180, "after180" => $after180, "managens" => $manageNS);
                                        }
                                        $ca->assign("domains", $storedDomains);
                                        $selectedIDs = $whmcs->get_req_var("domids");
                                        $ca->assign("selectedIDs", $selectedIDs);
                                        $ca->assign("renewals", WHMCS\Domains::getRenewableDomains(WHMCS\Session::get("uid")));
                                        $smartyvalues = array_merge($smartyvalues, clientAreaTablePageNav($numitems));
                                        $smartyvalues["allowrenew"] = $whmcs->get_config("EnableDomainRenewalOrders") ? true : false;
                                        $smartyvalues["warnings"] = $warnings;
                                        $ca->addOutputHookFunction("ClientAreaPageDomains");
                                    } else {
                                        if ($action == "domaindetails") {
                                            checkContactPermission("domains");
                                            $ca->setTemplate("clientareadomaindetails");
                                            $domain_data = $domains->getDomainsDatabyID($domainID);
                                            $ca->assign("changeAutoRenewStatusSuccessful", false);
                                            if ($domains->getData("status") == "Active") {
                                                $autorenew = $whmcs->get_req_var("autorenew");
                                                if ($autorenew == "enable") {
                                                    check_token();
                                                    update_query("tbldomains", array("donotrenew" => ""), array("id" => $id, "userid" => $legacyClient->getID()));
                                                    logActivity("Client Enabled Domain Auto Renew - Domain ID: " . $id . " - Domain: " . $domainName->getDomain());
                                                    $ca->assign("updatesuccess", true);
                                                    $ca->assign("changeAutoRenewStatusSuccessful", true);
                                                } else {
                                                    if ($autorenew == "disable") {
                                                        check_token();
                                                        disableAutoRenew($id);
                                                        $ca->assign("updatesuccess", true);
                                                        $ca->assign("changeAutoRenewStatusSuccessful", true);
                                                    }
                                                }
                                                $domain_data = $domains->getDomainsDatabyID($domainID);
                                            }
                                            $domain = $domains->getData("domain");
                                            $firstpaymentamount = $domains->getData("firstpaymentamount");
                                            $recurringamount = $domains->getData("recurringamount");
                                            $nextduedate = $domains->getData("nextduedate");
                                            $expirydate = $domains->getData("expirydate");
                                            $paymentmethod = $domains->getData("paymentmethod");
                                            $domainstatus = $domains->getData("status");
                                            $registrationperiod = $domains->getData("registrationperiod");
                                            $registrationdate = $domains->getData("registrationdate");
                                            $donotrenew = $domains->getData("donotrenew");
                                            $dnsmanagement = $domains->getData("dnsmanagement");
                                            $emailforwarding = $domains->getData("emailforwarding");
                                            $idprotection = $domains->getData("idprotection");
                                            $registrar = $domains->getModule();
                                            $gatewaysarray = getGatewaysArray();
                                            $paymentmethod = $gatewaysarray[$paymentmethod];
                                            $ca->addToBreadCrumb("clientarea.php?action=domaindetails&id=" . $domain_data["id"], $domain);
                                            $registrationdate = fromMySQLDate($registrationdate, 0, 1, "-");
                                            $nextduedate = fromMySQLDate($nextduedate, 0, 1, "-");
                                            $expirydate = fromMySQLDate($expirydate, 0, 1, "-");
                                            $rawstatus = $ca->getRawStatus($domainstatus);
                                            $allowrenew = false;
                                            if ($whmcs->get_config("EnableDomainRenewalOrders") && ($domainstatus == "Active" || $domainstatus == "Expired")) {
                                                $allowrenew = true;
                                            }
                                            $autorenew = $donotrenew ? false : true;
                                            $ca->assign("domainid", $domains->getData("id"));
                                            $ca->assign("domain", $domain);
                                            $ca->assign("firstpaymentamount", formatCurrency($firstpaymentamount));
                                            $ca->assign("recurringamount", formatCurrency($recurringamount));
                                            $ca->assign("registrationdate", $registrationdate);
                                            $ca->assign("nextduedate", $nextduedate);
                                            $ca->assign("expirydate", $expirydate);
                                            $ca->assign("registrationperiod", $registrationperiod);
                                            $ca->assign("paymentmethod", $paymentmethod);
                                            $ca->assign("systemStatus", $domainstatus);
                                            $ca->assign("status", Lang::trans("clientarea" . $rawstatus));
                                            $ca->assign("rawstatus", $rawstatus);
                                            $ca->assign("donotrenew", $donotrenew);
                                            $ca->assign("autorenew", $autorenew);
                                            $ca->assign("subaction", $sub);
                                            $ca->assign("addonstatus", array("dnsmanagement" => $dnsmanagement, "emailforwarding" => $emailforwarding, "idprotection" => $idprotection));
                                            if ($allowrenew) {
                                                $ca->assign("renew", $allowrenew);
                                            }
                                            $tlddata = get_query_vals("tbldomainpricing", "", array("extension" => "." . $domainName->getTLD()));
                                            $ca->assign("addons", array("dnsmanagement" => $tlddata["dnsmanagement"], "emailforwarding" => $tlddata["emailforwarding"], "idprotection" => $tlddata["idprotection"]));
                                            $addonscount = 0;
                                            if ($tlddata["dnsmanagement"]) {
                                                $addonscount++;
                                            }
                                            if ($tlddata["emailforwarding"]) {
                                                $addonscount++;
                                            }
                                            if ($tlddata["idprotection"]) {
                                                $addonscount++;
                                            }
                                            $ca->assign("addonscount", $addonscount);
                                            $result = select_query("tblpricing", "", array("type" => "domainaddons", "currency" => $currency["id"], "relid" => 0));
                                            $data = mysql_fetch_array($result);
                                            $domaindnsmanagementprice = $data["msetupfee"];
                                            $domainemailforwardingprice = $data["qsetupfee"];
                                            $domainidprotectionprice = $data["ssetupfee"];
                                            $ca->assign("addonspricing", array("dnsmanagement" => formatCurrency($domaindnsmanagementprice), "emailforwarding" => formatCurrency($domainemailforwardingprice), "idprotection" => formatCurrency($domainidprotectionprice)));
                                            $smartyvalues["updatesuccess"] = false;
                                            $ca->assign("registrarcustombuttonresult", "");
                                            if ($domainstatus == "Active" && $domains->getModule()) {
                                                $registrarclientarea = "";
                                                $ca->assign("registrar", $registrar);
                                                if ($sub == "savens") {
                                                    check_token();
                                                    checkContactPermission("managedomains");
                                                    $nameservers = $nschoice == "default" ? $domains->getDefaultNameservers() : array("ns1" => $ns1, "ns2" => $ns2, "ns3" => $ns3, "ns4" => $ns4, "ns5" => $ns5);
                                                    $success = $domains->moduleCall("SaveNameservers", $nameservers);
                                                    if ($success) {
                                                        $smartyvalues["updatesuccess"] = true;
                                                    } else {
                                                        $smartyvalues["error"] = $domains->getLastError();
                                                    }
                                                }
                                                if ($sub == "savereglock") {
                                                    check_token();
                                                    checkContactPermission("managedomains");
                                                    $newlockstatus = $whmcs->get_req_var("reglock") ? "locked" : "unlocked";
                                                    $success = $domains->moduleCall("SaveRegistrarLock", array("lockenabled" => $newlockstatus));
                                                    if ($success) {
                                                        $smartyvalues["updatesuccess"] = true;
                                                    } else {
                                                        $smartyvalues["error"] = $domains->getLastError();
                                                    }
                                                }
                                                $nameserversArray = array();
                                                for ($i = 1; $i <= 5; $i++) {
                                                    $nameserversArray[$i] = array("num" => $i, "label" => $whmcs->get_lang("domainnameserver" . $i), "value" => "");
                                                }
                                                $smartyvalues["defaultns"] = false;
                                                $smartyvalues["nameservers"] = array();
                                                $success = $domains->moduleCall("GetNameservers");
                                                if ($success) {
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        $nsvalue = $domains->getModuleReturn("ns" . $i);
                                                        $ca->assign("ns" . $i, $nsvalue);
                                                        $nameserversArray[$i]["value"] = $nsvalue;
                                                    }
                                                    $smartyvalues["managens"] = true;
                                                    $smartyvalues["nameservers"] = $nameserversArray;
                                                    $defaultns = array();
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        if (trim(WHMCS\Config\Setting::getValue("DefaultNameserver" . $i))) {
                                                            $defaultns[] = strtolower(trim(WHMCS\Config\Setting::getValue("DefaultNameserver" . $i)));
                                                        }
                                                    }
                                                    $isDefaultNs = true;
                                                    foreach ($nameserversArray as $nsInfo) {
                                                        $ns = $nsInfo["value"];
                                                        if ($ns && !in_array($ns, $defaultns)) {
                                                            $isDefaultNs = false;
                                                            break;
                                                        }
                                                    }
                                                    $smartyvalues["defaultns"] = $isDefaultNs;
                                                } else {
                                                    $smartyvalues["nameservererror"] = $domains->getLastError();
                                                    $smartyvalues["error"] = $smartyvalues["nameservererror"];
                                                }
                                                if ($managementOptions["locking"]) {
                                                    $success = $domains->moduleCall("GetRegistrarLock");
                                                    if ($success) {
                                                        $ca->assign("lockstatus", $domains->getModuleReturn());
                                                    }
                                                }
                                                $smartyvalues["managecontacts"] = $managementOptions["contacts"];
                                                $smartyvalues["registerns"] = $managementOptions["privatens"];
                                                $smartyvalues["dnsmanagement"] = $managementOptions["dnsmanagement"];
                                                $smartyvalues["emailforwarding"] = $managementOptions["emailforwarding"];
                                                $smartyvalues["getepp"] = $managementOptions["eppcode"];
                                                if ($managementOptions["release"]) {
                                                    $allowrelease = false;
                                                    if (isset($params["AllowClientTAGChange"]) && !$params["AllowClientTAGChange"]) {
                                                        $managementOptions["release"] = false;
                                                        $ca->assign("managementOptions", $managementOptions);
                                                    }
                                                    if ($managementOptions["release"]) {
                                                        $smartyvalues["releasedomain"] = true;
                                                        if ($sub == "releasedomain") {
                                                            check_token();
                                                            checkContactPermission("managedomains");
                                                            $success = $domains->moduleCall("ReleaseDomain", array("transfertag" => $transtag));
                                                            if ($success) {
                                                                $ca->assign("status", $whmcs->get_lang("clientareacancelled"));
                                                                logActivity("Client Requested Domain Release to Tag " . $transtag);
                                                            } else {
                                                                $smartyvalues["error"] = $domains->getLastError();
                                                            }
                                                        }
                                                    } else {
                                                        $smartyvalues["releasedomain"] = false;
                                                    }
                                                }
                                                $allowedclientregistrarfunctions = array();
                                                if ($domains->hasFunction("ClientAreaAllowedFunctions")) {
                                                    $success = $domains->moduleCall("ClientAreaAllowedFunctions");
                                                    $registrarallowedfunctions = $domains->getModuleReturn();
                                                    if (is_array($registrarallowedfunctions)) {
                                                        foreach ($registrarallowedfunctions as $v) {
                                                            $allowedclientregistrarfunctions[] = $v;
                                                        }
                                                    }
                                                }
                                                if ($domains->hasFunction("ClientAreaCustomButtonArray")) {
                                                    $success = $domains->moduleCall("ClientAreaCustomButtonArray");
                                                    $registrarcustombuttons = $domains->getModuleReturn();
                                                    if (is_array($registrarcustombuttons)) {
                                                        foreach ($registrarcustombuttons as $k => $v) {
                                                            $allowedclientregistrarfunctions[] = $v;
                                                        }
                                                    }
                                                    $ca->assign("registrarcustombuttons", $registrarcustombuttons);
                                                }
                                                if ($modop == "custom" && in_array($a, $allowedclientregistrarfunctions)) {
                                                    checkContactPermission("managedomains");
                                                    $success = $domains->moduleCall($a);
                                                    $data = $domains->getModuleReturn();
                                                    if (is_array($data)) {
                                                        if (isset($data["templatefile"])) {
                                                            if (!isValidforPath($registrar)) {
                                                                throw new WHMCS\Exception\Fatal("Invalid Registrar Module Name");
                                                            }
                                                            if (!isValidforPath($data["templatefile"])) {
                                                                throw new WHMCS\Exception\Fatal("Invalid Template Filename");
                                                            }
                                                            $ca->setTemplate("/modules/registrars/" . $registrar . "/" . $data["templatefile"] . ".tpl");
                                                        }
                                                        if (isset($data["breadcrumb"]) && is_array($data["breadcrumb"])) {
                                                            foreach ($data["breadcrumb"] as $k => $v) {
                                                                $ca->addToBreadCrumb($k, $v);
                                                            }
                                                        }
                                                        if (is_array($data["vars"])) {
                                                            foreach ($data["vars"] as $k => $v) {
                                                                $smartyvalues[$k] = $v;
                                                            }
                                                        }
                                                    } else {
                                                        if (!$data || $data == "success") {
                                                            $ca->assign("registrarcustombuttonresult", "success");
                                                        } else {
                                                            $ca->assign("registrarcustombuttonresult", $data);
                                                        }
                                                    }
                                                }
                                                if (checkContactPermission("managedomains", true)) {
                                                    $moduletemplatefile = "";
                                                    $result = select_query("tbldomains", "idprotection", array("id" => $domains->getData("id")));
                                                    $data = mysql_fetch_assoc($result);
                                                    $idprotection = $data["idprotection"] ? true : false;
                                                    $success = $domains->moduleCall("ClientArea", array("protectenable" => $idprotection));
                                                    $result = $domains->getModuleReturn();
                                                    if (is_array($result)) {
                                                        if (isset($result["templatefile"])) {
                                                            if (!isValidforPath($registrar)) {
                                                                throw new WHMCS\Exception\Fatal("Invalid Registrar Module Name");
                                                            }
                                                            if (!isValidforPath($result["templatefile"])) {
                                                                throw new WHMCS\Exception\Fatal("Invalid Template Filename");
                                                            }
                                                            $moduletemplatefile = "/modules/registrars/" . $registrar . "/" . $result["templatefile"] . ".tpl";
                                                        }
                                                    } else {
                                                        $registrarclientarea = $result;
                                                    }
                                                    if (!$moduletemplatefile && isValidforPath($registrar) && file_exists(ROOTDIR . "/modules/registrars/" . $registrar . "/clientarea.tpl")) {
                                                        $moduletemplatefile = "/modules/registrars/" . $registrar . "/clientarea.tpl";
                                                    }
                                                    if ($moduletemplatefile) {
                                                        if (is_array($result["vars"])) {
                                                            foreach ($result["vars"] as $k => $v) {
                                                                $params[$k] = $v;
                                                            }
                                                        }
                                                        $registrarclientarea = $ca->getSingleTPLOutput($moduletemplatefile, $moduleparams);
                                                    }
                                                }
                                                $smartyvalues["registrarclientarea"] = $registrarclientarea;
                                            }
                                            run_hook("ClientAreaDomainDetails", array("domain" => $domainModel));
                                            $hookResponses = run_hook("ClientAreaDomainDetailsOutput", array("domain" => $domainModel));
                                            $ca->assign("hookOutput", $hookResponses);
                                            $ca->addOutputHookFunction("ClientAreaPageDomainDetails");
                                        } else {
                                            if ($action == "domaincontacts") {
                                                checkContactPermission("managedomains");
                                                $ca->setTemplate("clientareadomaincontactinfo");
                                                $contactsarray = $legacyClient->getContactsWithAddresses();
                                                $smartyvalues["contacts"] = $contactsarray;
                                                if (!$domainData || !$domains->isActive() || !$domains->hasFunction("GetContactDetails")) {
                                                    redir("action=domains", "clientarea.php");
                                                }
                                                $ca->addToBreadCrumb("clientarea.php?action=domaindetails&id=" . $domainData["id"], $domainData["domain"]);
                                                $ca->addToBreadCrumb("#", $whmcs->get_lang("domaincontactinfo"));
                                                $smartyvalues["successful"] = false;
                                                $smartyvalues["error"] = "";
                                                if ($sub == "save") {
                                                    check_token();
                                                    $contactdetails = $whmcs->get_req_var("contactdetails");
                                                    $wc = $whmcs->get_req_var("wc");
                                                    $sel = $whmcs->get_req_var("sel");
                                                    foreach ($wc as $wc_key => $wc_val) {
                                                        if ($wc_val == "contact") {
                                                            $selectedContact = $sel[$wc_key];
                                                            $selectedContactType = substr($selectedContact, 0, 1);
                                                            $selectedContactID = substr($selectedContact, 1);
                                                            $tmpcontactdetails = array();
                                                            if ($selectedContactType == "u") {
                                                                $tmpcontactdetails = $legacyClient->getDetails();
                                                            } else {
                                                                if ($selectedContactType == "c") {
                                                                    $tmpcontactdetails = $legacyClient->getDetails($selectedContactID);
                                                                }
                                                            }
                                                            $contactdetails[$wc_key] = $domains->buildWHOISSaveArray($tmpcontactdetails);
                                                        }
                                                    }
                                                    $success = $domains->moduleCall("SaveContactDetails", array("contactdetails" => foreignChrReplace($contactdetails)));
                                                    if ($success) {
                                                        $smartyvalues["successful"] = true;
                                                    } else {
                                                        $smartyvalues["error"] = $domains->getLastError();
                                                    }
                                                }
                                                $success = $domains->moduleCall("GetContactDetails");
                                                if ($success) {
                                                    if ($sub == "save" && $smartyvalues["successful"] === false && isset($contactdetails)) {
                                                        $contactDetails = $contactdetails;
                                                    } else {
                                                        $contactDetails = $domains->getModuleReturn();
                                                    }
                                                    $contactTranslations = array();
                                                    foreach ($contactDetails as $contactType) {
                                                        foreach (array_keys($contactType) as $contactFieldName) {
                                                            if (Lang::trans("domaincontactdetails." . $contactFieldName) == "domaincontactdetails." . $contactFieldName) {
                                                                $contactTranslations[$contactFieldName] = Lang::trans("domaincontactdetails." . $contactFieldName);
                                                            } else {
                                                                $contactTranslations[$contactFieldName] = $contactFieldName;
                                                            }
                                                        }
                                                    }
                                                    $smartyvalues["contactdetails"] = $contactDetails;
                                                    $smartyvalues["contactdetailstranslations"] = $contactTranslations;
                                                } else {
                                                    $smartyvalues["error"] = $domains->getLastError();
                                                }
                                                $smartyvalues["domainid"] = $domains->getData("id");
                                                $smartyvalues["domain"] = $domains->getData("domain");
                                                $smartyvalues["contacts"] = $legacyClient->getContactsWithAddresses();
                                                $ca->addOutputHookFunction("ClientAreaPageDomainContacts");
                                            } else {
                                                if ($action == "domainemailforwarding") {
                                                    checkContactPermission("managedomains");
                                                    $ca->setTemplate("clientareadomainemailforwarding");
                                                    if (!$domainData["emailforwarding"] || !$domains->isActive() || !$domains->hasFunction("GetEmailForwarding")) {
                                                        redir("action=domains", "clientarea.php");
                                                    }
                                                    $ca->addToBreadCrumb("clientarea.php?action=domaindetails&id=" . $domainData["id"], $domainData["domain"]);
                                                    $ca->addToBreadCrumb("#", $whmcs->get_lang("domainemailforwarding"));
                                                    if ($sub == "save") {
                                                        check_token();
                                                        $key = 0;
                                                        $vars = array();
                                                        if ($whmcs->get_req_var("emailforwarderprefix")) {
                                                            $vars["prefix"] = $whmcs->get_req_var("emailforwarderprefix");
                                                            $vars["forwardto"] = $whmcs->get_req_var("emailforwarderforwardto");
                                                        }
                                                        if ($whmcs->get_req_var("emailforwarderprefixnew")) {
                                                            $vars["prefix"][] = $whmcs->get_req_var("emailforwarderprefixnew");
                                                            $vars["forwardto"][] = $whmcs->get_req_var("emailforwarderforwardtonew");
                                                        }
                                                        $success = $domains->moduleCall("SaveEmailForwarding", $vars);
                                                        if (!$success) {
                                                            $smartyvalues["error"] = $domains->getLastError();
                                                        }
                                                    }
                                                    $success = $domains->moduleCall("GetEmailForwarding");
                                                    if (!$success) {
                                                        $smartyvalues["error"] = $domains->getLastError();
                                                    }
                                                    $smartyvalues["domainid"] = $domainData["id"];
                                                    $smartyvalues["domain"] = $domainData["domain"];
                                                    if ($domains->getModuleReturn("external")) {
                                                        $ca->assign("external", true);
                                                        $ca->assign("code", $domains->getModuleReturn("code"));
                                                    } else {
                                                        $ca->assign("emailforwarders", $domains->getModuleReturn());
                                                    }
                                                    $ca->addOutputHookFunction("ClientAreaPageDomainEmailForwarding");
                                                } else {
                                                    if ($action == "domaindns") {
                                                        checkContactPermission("managedomains");
                                                        $ca->setTemplate("clientareadomaindns");
                                                        if (!$domainData["dnsmanagement"] || !$domains->isActive() || !$domains->hasFunction("GetDNS")) {
                                                            redir("action=domains", "clientarea.php");
                                                        }
                                                        $ca->addToBreadCrumb("clientarea.php?action=domaindetails&id=" . $domainData["id"], $domainData["domain"]);
                                                        $ca->addToBreadCrumb("#", $whmcs->get_lang("domaindnsmanagement"));
                                                        if ($sub == "save") {
                                                            check_token();
                                                            $vars = array();
                                                            foreach ($_POST["dnsrecordhost"] as $num => $dnshost) {
                                                                $vars[] = array("hostname" => $dnshost, "type" => $_POST["dnsrecordtype"][$num], "address" => WHMCS\Input\Sanitize::decode($_POST["dnsrecordaddress"][$num]), "priority" => $_POST["dnsrecordpriority"][$num], "recid" => $_POST["dnsrecid"][$num]);
                                                            }
                                                            $success = $domains->moduleCall("SaveDNS", array("dnsrecords" => $vars));
                                                            if (!$success) {
                                                                $smartyvalues["error"] = $domains->getLastError();
                                                            }
                                                        }
                                                        $success = $domains->moduleCall("GetDNS");
                                                        if (!$success) {
                                                            $smartyvalues["error"] = $domains->getLastError();
                                                        }
                                                        $smartyvalues["domainid"] = $domainData["id"];
                                                        $smartyvalues["domain"] = $domainData["domain"];
                                                        if ($domains->getModuleReturn("external")) {
                                                            $ca->assign("external", true);
                                                            $ca->assign("code", $domains->getModuleReturn("code"));
                                                        } else {
                                                            $records = $domains->getModuleReturn();
                                                            foreach ($records as &$record) {
                                                                $record["hostname"] = WHMCS\Input\Sanitize::encode($record["hostname"]);
                                                                $record["address"] = WHMCS\Input\Sanitize::encode($record["address"]);
                                                            }
                                                            unset($record);
                                                            $ca->assign("dnsrecords", $records);
                                                        }
                                                        $ca->addOutputHookFunction("ClientAreaPageDomainDNSManagement");
                                                    } else {
                                                        if ($action == "domaingetepp") {
                                                            checkContactPermission("managedomains");
                                                            $ca->setTemplate("clientareadomaingetepp");
                                                            if (!$domainData || !$domains->isActive() || !$domains->hasFunction("GetEPPCode")) {
                                                                redir("action=domains", "clientarea.php");
                                                            }
                                                            $ca->addToBreadCrumb("clientarea.php?action=domaindetails&id=" . $domainData["id"], $domainData["domain"]);
                                                            $ca->addToBreadCrumb("#", $whmcs->get_lang("domaingeteppcode"));
                                                            $smartyvalues["domainid"] = $domainData["id"];
                                                            $smartyvalues["domain"] = $domainData["domain"];
                                                            $success = $domains->moduleCall("GetEPPCode");
                                                            if (!$success) {
                                                                $smartyvalues["error"] = $domains->getLastError();
                                                            } else {
                                                                $smartyvalues["eppcode"] = htmlspecialchars($domains->getModuleReturn("eppcode"));
                                                            }
                                                            $ca->addOutputHookFunction("ClientAreaPageDomainEPPCode");
                                                        } else {
                                                            if ($action == "domainregisterns") {
                                                                checkContactPermission("managedomains");
                                                                $ca->setTemplate("clientareadomainregisterns");
                                                                if (!$domainData || !$domains->isActive() || !$domains->hasFunction("RegisterNameserver")) {
                                                                    redir("action=domains", "clientarea.php");
                                                                }
                                                                $ca->addToBreadCrumb("clientarea.php?action=domaindetails&id=" . $domainData["id"], $domainData["domain"]);
                                                                $ca->addToBreadCrumb("#", $whmcs->get_lang("domainregisterns"));
                                                                $smartyvalues["domainid"] = $domainData["id"];
                                                                $smartyvalues["domain"] = $domainData["domain"];
                                                                $result = "";
                                                                $vars = array();
                                                                $ns = $whmcs->get_req_var("ns");
                                                                if ($sub == "register") {
                                                                    check_token();
                                                                    $ipaddress = $whmcs->get_req_var("ipaddress");
                                                                    $nameserver = $ns . "." . $domainData["domain"];
                                                                    $vars["nameserver"] = $nameserver;
                                                                    $vars["ipaddress"] = $ipaddress;
                                                                    $success = $domains->moduleCall("RegisterNameserver", $vars);
                                                                    $result = $success ? Lang::trans("domainregisternsregsuccess") : $domains->getLastError();
                                                                } else {
                                                                    if ($sub == "modify") {
                                                                        check_token();
                                                                        $nameserver = $ns . "." . $domainData["domain"];
                                                                        $currentipaddress = $whmcs->get_req_var("currentipaddress");
                                                                        $newipaddress = $whmcs->get_req_var("newipaddress");
                                                                        $vars["nameserver"] = $nameserver;
                                                                        $vars["currentipaddress"] = $currentipaddress;
                                                                        $vars["newipaddress"] = $newipaddress;
                                                                        $success = $domains->moduleCall("ModifyNameserver", $vars);
                                                                        $result = $success ? Lang::trans("domainregisternsmodsuccess") : $domains->getLastError();
                                                                    } else {
                                                                        if ($sub == "delete") {
                                                                            check_token();
                                                                            $nameserver = $ns . "." . $domainData["domain"];
                                                                            $vars["nameserver"] = $nameserver;
                                                                            $success = $domains->moduleCall("DeleteNameserver", $vars);
                                                                            $result = $success ? Lang::trans("domainregisternsdelsuccess") : $domains->getLastError();
                                                                        }
                                                                    }
                                                                }
                                                                $smartyvalues["result"] = $result;
                                                                $ca->addOutputHookFunction("ClientAreaPageDomainRegisterNameservers");
                                                            } else {
                                                                if ($action == "domainrenew") {
                                                                    checkContactPermission("orders");
                                                                    redir("gid=renewals", "cart.php");
                                                                } else {
                                                                    if ($action == "invoices") {
                                                                        checkContactPermission("invoices");
                                                                        $ca->setDisplayTitle(Lang::trans("invoices"));
                                                                        $ca->setTagLine(Lang::trans("invoicesintro"));
                                                                        $ca->setTemplate("clientareainvoices");
                                                                        $numitems = get_query_val("tblinvoices", "COUNT(*)", array("userid" => $legacyClient->getID()));
                                                                        list($orderby, $sort, $limit) = clientAreaTableInit("inv", "default", "ASC", $numitems);
                                                                        $smartyvalues["orderby"] = $orderby;
                                                                        $smartyvalues["sort"] = strtolower($sort);
                                                                        switch ($orderby) {
                                                                            case "date":
                                                                            case "duedate":
                                                                            case "total":
                                                                            case "status":
                                                                                break;
                                                                            case "invoicenum":
                                                                                $orderby = "invoicenum` " . $sort . ", `id";
                                                                                break;
                                                                            default:
                                                                                $orderby = "status` DESC, `duedate";
                                                                                break;
                                                                        }
                                                                        $invoice = new WHMCS\Invoice();
                                                                        $invoices = $invoice->getInvoices("", $legacyClient->getID(), $orderby, $sort, $limit);
                                                                        $ca->assign("invoices", $invoices);
                                                                        if ($invoice->getTotalBalance() <= 0) {
                                                                            $ca->assign("nobalance", true);
                                                                        }
                                                                        $ca->assign("totalbalance", $invoice->getTotalBalanceFormatted());
                                                                        $ca->assign("masspay", WHMCS\Config\Setting::getValue("EnableMassPay"));
                                                                        $smartyvalues = array_merge($smartyvalues, clientAreaTablePageNav($numitems));
                                                                        $ca->addOutputHookFunction("ClientAreaPageInvoices");
                                                                    } else {
                                                                        if ($action == "emails") {
                                                                            checkContactPermission("emails");
                                                                            $ca->setDisplayTitle(Lang::trans("clientareaemails"));
                                                                            $ca->setTagLine(Lang::trans("clientareaemaildesc"));
                                                                            $ca->setTemplate("clientareaemails");
                                                                            $result = select_query("tblemails", "COUNT(*)", array("userid" => $legacyClient->getID()), "id", "DESC");
                                                                            $data = mysql_fetch_array($result);
                                                                            $numitems = $data[0];
                                                                            list($orderby, $sort, $limit) = clientAreaTableInit("emails", "date", "DESC", $numitems);
                                                                            $smartyvalues["orderby"] = $orderby;
                                                                            $smartyvalues["sort"] = strtolower($sort);
                                                                            if ($orderby == "subject") {
                                                                                $orderby = "subject";
                                                                            } else {
                                                                                $orderby = "date";
                                                                            }
                                                                            $emails = array();
                                                                            $result = select_query("tblemails", "", array("userid" => $legacyClient->getID()), $orderby, $sort, $limit);
                                                                            while ($data = mysql_fetch_array($result)) {
                                                                                $id = $data["id"];
                                                                                $date = $data["date"];
                                                                                $normalisedDate = $date;
                                                                                $subject = $data["subject"];
                                                                                $date = fromMySQLDate($date, 1, 1);
                                                                                $emails[] = array("id" => (int) $id, "date" => WHMCS\Input\Sanitize::makeSafeForOutput($date), "normalisedDate" => $normalisedDate, "subject" => WHMCS\Input\Sanitize::makeSafeForOutput($subject));
                                                                            }
                                                                            $ca->assign("emails", $emails);
                                                                            $smartyvalues = array_merge($smartyvalues, clientAreaTablePageNav($numitems));
                                                                            $ca->addOutputHookFunction("ClientAreaPageEmails");
                                                                        } else {
                                                                            if ($action == "cancel") {
                                                                                checkContactPermission("orders");
                                                                                $service = new WHMCS\Service($id, $legacyClient->getID());
                                                                                if ($service->isNotValid()) {
                                                                                    redir("action=products", "clientarea.php");
                                                                                }
                                                                                $serviceModel = WHMCS\Service\Service::find($service->getID());
                                                                                $allowedstatuscancel = array("Active", "Suspended");
                                                                                if (!in_array($service->getData("status"), $allowedstatuscancel)) {
                                                                                    redir("action=productdetails&id=" . $id);
                                                                                }
                                                                                $ca->setDisplayTitle(Lang::trans("clientareacancelrequest"));
                                                                                $ca->setTemplate("clientareacancelrequest");
                                                                                $ca->addToBreadCrumb("clientarea.php?action=productdetails&id=" . $id, $whmcs->get_lang("clientareaproductdetails"));
                                                                                $ca->addToBreadCrumb("cancel&id=" . $id, $whmcs->get_lang("clientareacancelrequest"));
                                                                                $clientsdetails = getClientsDetails($legacyClient->getID());
                                                                                $smartyvalues["id"] = $service->getData("id");
                                                                                $smartyvalues["groupname"] = $service->getData("groupname");
                                                                                $smartyvalues["productname"] = $service->getData("productname");
                                                                                $smartyvalues["domain"] = $service->getData("domain");
                                                                                $cancelrequests = get_query_val("tblcancelrequests", "COUNT(*)", array("relid" => $id));
                                                                                if ($cancelrequests) {
                                                                                    $smartyvalues["invalid"] = true;
                                                                                } else {
                                                                                    $smartyvalues["invalid"] = false;
                                                                                    $smartyvalues["error"] = false;
                                                                                    $smartyvalues["requested"] = false;
                                                                                    if ($sub == "submit") {
                                                                                        check_token();
                                                                                        if (!trim($cancellationreason)) {
                                                                                            $smartyvalues["error"] = true;
                                                                                        }
                                                                                        if (!$smartyvalues["error"]) {
                                                                                            if (!in_array($type, array("Immediate", "End of Billing Period"))) {
                                                                                                $type = "End of Billing Period";
                                                                                            }
                                                                                            createCancellationRequest($legacyClient->getID(), $id, $cancellationreason, $type);
                                                                                            if ($canceldomain) {
                                                                                                $domainid = get_query_val("tbldomains", "id", array("userid" => $legacyClient->getID(), "domain" => $service->getData("domain")));
                                                                                                if ($domainid) {
                                                                                                    disableAutoRenew($domainid);
                                                                                                }
                                                                                            }
                                                                                            sendMessage("Cancellation Request Confirmation", $id);
                                                                                            sendAdminMessage("New Cancellation Request", array("client_id" => $legacyClient->getID(), "clientname" => $clientsdetails["firstname"] . " " . $clientsdetails["lastname"], "service_id" => $id, "product_name" => $service->getData("productname"), "service_cancellation_type" => $type, "service_cancellation_reason" => $cancellationreason), "account");
                                                                                            $smartyvalues["requested"] = true;
                                                                                        }
                                                                                    }
                                                                                    if ($service->getData("domain")) {
                                                                                        $data = get_query_vals("tbldomains", "id,recurringamount,registrationperiod,nextduedate", array("userid" => $legacyClient->getID(), "domain" => $service->getData("domain"), "status" => "Active", "donotrenew" => ""));
                                                                                        $smartyvalues["domainid"] = $data["id"];
                                                                                        $smartyvalues["domainprice"] = formatCurrency($data["recurringamount"]);
                                                                                        $smartyvalues["domainregperiod"] = $data["registrationperiod"];
                                                                                        $smartyvalues["domainnextduedate"] = fromMySQLDate($data["nextduedate"], 0, 1);
                                                                                    }
                                                                                }
                                                                                $ca->addOutputHookFunction("ClientAreaPageCancellation");
                                                                            } else {
                                                                                if ($action == "addfunds") {
                                                                                    checkContactPermission("invoices");
                                                                                    $ca->setDisplayTitle(Lang::trans("addfunds"));
                                                                                    $ca->setTagLine(Lang::trans("addfundsintro"));
                                                                                    $clientsdetails = getClientsDetails();
                                                                                    $addfundsmaxbal = convertCurrency(WHMCS\Config\Setting::getValue("AddFundsMaximumBalance"), 1, $clientsdetails["currency"]);
                                                                                    $addfundsmax = convertCurrency(WHMCS\Config\Setting::getValue("AddFundsMaximum"), 1, $clientsdetails["currency"]);
                                                                                    $addfundsmin = convertCurrency(WHMCS\Config\Setting::getValue("AddFundsMinimum"), 1, $clientsdetails["currency"]);
                                                                                    $result = select_query("tblorders", "COUNT(*)", array("userid" => $legacyClient->getID(), "status" => "Active"));
                                                                                    $data = mysql_fetch_array($result);
                                                                                    $numactiveorders = $data[0];
                                                                                    $smartyvalues["addfundsdisabled"] = false;
                                                                                    $smartyvalues["notallowed"] = false;
                                                                                    if (!WHMCS\Config\Setting::getValue("AddFundsRequireOrder")) {
                                                                                        $numactiveorders = 1;
                                                                                    }
                                                                                    if (!WHMCS\Config\Setting::getValue("AddFundsEnabled")) {
                                                                                        $smartyvalues["addfundsdisabled"] = true;
                                                                                    } else {
                                                                                        if (!$numactiveorders) {
                                                                                            $smartyvalues["notallowed"] = true;
                                                                                        } else {
                                                                                            $amount = $whmcs->get_req_var("amount");
                                                                                            if ($amount) {
                                                                                                check_token();
                                                                                                $totalcredit = $clientsdetails["credit"] + $amount;
                                                                                                if ($addfundsmaxbal < $totalcredit) {
                                                                                                    $errormessage = Lang::trans("addfundsmaximumbalanceerror") . " " . formatCurrency($addfundsmaxbal);
                                                                                                }
                                                                                                if ($addfundsmax < $amount) {
                                                                                                    $errormessage = Lang::trans("addfundsmaximumerror") . " " . formatCurrency($addfundsmax);
                                                                                                }
                                                                                                if ($amount < $addfundsmin) {
                                                                                                    $errormessage = Lang::trans("addfundsminimumerror") . " " . formatCurrency($addfundsmin);
                                                                                                }
                                                                                                if ($errormessage) {
                                                                                                    $ca->assign("errormessage", $errormessage);
                                                                                                } else {
                                                                                                    $paymentmethods = getGatewaysArray();
                                                                                                    if (!array_key_exists($paymentmethod, $paymentmethods)) {
                                                                                                        $paymentmethod = getClientsPaymentMethod($legacyClient->getID());
                                                                                                    }
                                                                                                    $paymentmethod = WHMCS\Gateways::makeSafeName($paymentmethod);
                                                                                                    if (!$paymentmethod) {
                                                                                                        exit("Unexpected payment method value. Exiting.");
                                                                                                    }
                                                                                                    require ROOTDIR . "/includes/processinvoices.php";
                                                                                                    $invoiceid = createInvoices($legacyClient->getID());
                                                                                                    insert_query("tblinvoiceitems", array("userid" => $legacyClient->getID(), "type" => "AddFunds", "relid" => "", "description" => Lang::trans("addfunds"), "amount" => $amount, "taxed" => "0", "duedate" => "now()", "paymentmethod" => $paymentmethod));
                                                                                                    $invoiceid = createInvoices($legacyClient->getID(), "", true);
                                                                                                    $result = select_query("tblpaymentgateways", "value", array("gateway" => $paymentmethod, "setting" => "type"));
                                                                                                    $data = mysql_fetch_array($result);
                                                                                                    $gatewaytype = $data["value"];
                                                                                                    if ($gatewaytype == "CC" || $gatewaytype == "OfflineCC") {
                                                                                                        if (!isValidforPath($paymentmethod)) {
                                                                                                            exit("Invalid Payment Gateway Name");
                                                                                                        }
                                                                                                        $gatewaypath = ROOTDIR . "/modules/gateways/" . $paymentmethod . ".php";
                                                                                                        if (file_exists($gatewaypath)) {
                                                                                                            require_once $gatewaypath;
                                                                                                        }
                                                                                                        if (!function_exists($paymentmethod . "_link")) {
                                                                                                            redir("invoiceid=" . $invoiceid, "creditcard.php");
                                                                                                        }
                                                                                                    }
                                                                                                    $invoice = new WHMCS\Invoice($invoiceid);
                                                                                                    $paymentbutton = $invoice->getPaymentLink();
                                                                                                    $ca->setTemplate("forwardpage");
                                                                                                    $ca->assign("message", Lang::trans("forwardingtogateway"));
                                                                                                    $ca->assign("code", $paymentbutton);
                                                                                                    $ca->assign("invoiceid", $invoiceid);
                                                                                                    $ca->output();
                                                                                                    exit;
                                                                                                }
                                                                                            } else {
                                                                                                $amount = $addfundsmin;
                                                                                            }
                                                                                        }
                                                                                    }
                                                                                    $ca->setTemplate("clientareaaddfunds");
                                                                                    $ca->assign("minimumamount", formatCurrency($addfundsmin));
                                                                                    $ca->assign("maximumamount", formatCurrency($addfundsmax));
                                                                                    $ca->assign("maximumbalance", formatCurrency($addfundsmaxbal));
                                                                                    $ca->assign("amount", format_as_currency($amount));
                                                                                    $gatewayslist = showPaymentGatewaysList(array(), $legacyClient->getID());
                                                                                    $ca->assign("gateways", $gatewayslist);
                                                                                    $ca->addOutputHookFunction("ClientAreaPageAddFunds");
                                                                                } else {
                                                                                    if ($action == "masspay") {
                                                                                        checkContactPermission("invoices");
                                                                                        $ca->setDisplayTitle(Lang::trans("masspaytitle"));
                                                                                        $ca->setTagLine(Lang::trans("masspayintro"));
                                                                                        $ca->setTemplate("masspay");
                                                                                        if (!WHMCS\Config\Setting::getValue("EnableMassPay")) {
                                                                                            redir("action=invoices");
                                                                                        }
                                                                                        if ($all) {
                                                                                            $invoiceids = array();
                                                                                            $result = full_query("SELECT id FROM tblinvoices WHERE userid = " . $legacyClient->getID() . " AND status='Unpaid' AND (select count(id) from tblinvoiceitems where invoiceid=tblinvoices.id and type='Invoice')<=0 ORDER BY id DESC");
                                                                                            while ($data = mysql_fetch_array($result)) {
                                                                                                $invoiceids[] = $data["id"];
                                                                                            }
                                                                                        } else {
                                                                                            if (count($invoiceids) == 0) {
                                                                                                redir();
                                                                                            } else {
                                                                                                if (count($invoiceids) == 1) {
                                                                                                    redir(array("id" => (int) $invoiceids[0]), "viewinvoice.php");
                                                                                                } else {
                                                                                                    $tmp_invoiceids = db_escape_numarray($invoiceids);
                                                                                                    $invoiceids = array();
                                                                                                    $result = select_query("tblinvoices", "id", array("userid" => $legacyClient->getID(), "status" => "Unpaid", "id" => array("sqltype" => "IN", "values" => $tmp_invoiceids)), "id", "DESC");
                                                                                                    while ($data = mysql_fetch_array($result)) {
                                                                                                        $invoiceids[] = $data["id"];
                                                                                                    }
                                                                                                }
                                                                                            }
                                                                                        }
                                                                                        $xmasspays = array();
                                                                                        $result = select_query("tblinvoiceitems", "invoiceid,relid", array("tblinvoiceitems.userid" => $legacyClient->getID(), "tblinvoiceitems.type" => "Invoice", "tblinvoices.status" => "Unpaid"), "", "", "", "tblinvoices ON tblinvoices.id=tblinvoiceitems.invoiceid");
                                                                                        while ($data = mysql_fetch_array($result)) {
                                                                                            $xmasspays[$data[0]][$data[1]] = 1;
                                                                                        }
                                                                                        if (count($xmasspays)) {
                                                                                            $numsel = count($invoiceids);
                                                                                            foreach ($xmasspays as $iid => $vals) {
                                                                                                if (count($vals) == $numsel) {
                                                                                                    foreach ($invoiceids as $z) {
                                                                                                        unset($vals[$z]);
                                                                                                    }
                                                                                                    if (!count($vals)) {
                                                                                                        redir("id=" . (int) $iid, "viewinvoice.php");
                                                                                                    }
                                                                                                }
                                                                                            }
                                                                                        }
                                                                                        $geninvoice = $whmcs->get_req_var("geninvoice");
                                                                                        if ($geninvoice) {
                                                                                            check_token();
                                                                                        }
                                                                                        $paymentmethods = getGatewaysArray();
                                                                                        if (!count($paymentmethods)) {
                                                                                            redir("", "clientarea.php");
                                                                                        }
                                                                                        if (!array_key_exists($paymentmethod, $paymentmethods)) {
                                                                                            $paymentmethod = getClientsPaymentMethod($legacyClient->getID());
                                                                                        }
                                                                                        $paymentmethod = WHMCS\Gateways::makeSafeName($paymentmethod);
                                                                                        if (!$paymentmethod) {
                                                                                            exit("Unexpected payment method value. Exiting.");
                                                                                        }
                                                                                        $subtotal = $credit = $tax = $tax2 = $total = $partialpayments = 0;
                                                                                        $invoiceitems = array();
                                                                                        foreach ($invoiceids as $invoiceid) {
                                                                                            $invoiceid = (int) $invoiceid;
                                                                                            $result = select_query("tblinvoices", "", array("id" => $invoiceid, "userid" => $legacyClient->getID()));
                                                                                            $data = mysql_fetch_array($result);
                                                                                            $invoiceid = (int) $data["id"];
                                                                                            if ($invoiceid) {
                                                                                                $subtotal += $data["subtotal"];
                                                                                                $credit += $data["credit"];
                                                                                                $tax += $data["tax"];
                                                                                                $tax2 += $data["tax2"];
                                                                                                $thistotal = $data["total"];
                                                                                                $total += $thistotal;
                                                                                                $result = select_query("tblaccounts", "SUM(amountin)", array("invoiceid" => $invoiceid));
                                                                                                $data = mysql_fetch_array($result);
                                                                                                $thispayments = $data[0];
                                                                                                $partialpayments += $thispayments;
                                                                                                $thistotal = $thistotal - $thispayments;
                                                                                                if ($geninvoice) {
                                                                                                    insert_query("tblinvoiceitems", array("userid" => $legacyClient->getID(), "type" => "Invoice", "relid" => $invoiceid, "description" => Lang::trans("invoicenumber") . $invoiceid, "amount" => $thistotal, "duedate" => "now()", "paymentmethod" => $paymentmethod));
                                                                                                }
                                                                                                $result = select_query("tblinvoiceitems", "", array("invoiceid" => $invoiceid));
                                                                                                while ($data = mysql_fetch_array($result)) {
                                                                                                    $invoiceitems[$invoiceid][] = array("id" => $data["id"], "description" => nl2br($data["description"]), "amount" => formatCurrency($data["amount"]));
                                                                                                }
                                                                                            }
                                                                                        }
                                                                                        if ($geninvoice) {
                                                                                            foreach ($xmasspays as $iid => $vals) {
                                                                                                update_query("tblinvoices", array("status" => "Cancelled"), array("id" => $iid, "userid" => $legacyClient->getID()));
                                                                                            }
                                                                                            require ROOTDIR . "/includes/processinvoices.php";
                                                                                            $invoiceid = createInvoices($legacyClient->getID(), true, true, array("invoices" => $invoiceids));
                                                                                            $invoiceid = (int) $invoiceid;
                                                                                            $paymentmethod = WHMCS\Gateways::makeSafeName($paymentmethod);
                                                                                            $result = select_query("tblpaymentgateways", "value", array("gateway" => $paymentmethod, "setting" => "type"));
                                                                                            $data = mysql_fetch_array($result);
                                                                                            $gatewaytype = $data["value"];
                                                                                            if ($gatewaytype == "CC" || $gatewaytype == "OfflineCC") {
                                                                                                if (!isValidforPath($paymentmethod)) {
                                                                                                    exit("Invalid Payment Gateway Name");
                                                                                                }
                                                                                                $gatewaypath = ROOTDIR . "/modules/gateways/" . $paymentmethod . ".php";
                                                                                                if (file_exists($gatewaypath)) {
                                                                                                    require_once $gatewaypath;
                                                                                                }
                                                                                                if (!function_exists($paymentmethod . "_link")) {
                                                                                                    redir("invoiceid=" . $invoiceid, "creditcard.php");
                                                                                                }
                                                                                            }
                                                                                            $invoice = new WHMCS\Invoice($invoiceid);
                                                                                            $paymentbutton = $invoice->getPaymentLink();
                                                                                            $ca->setTemplate("forwardpage");
                                                                                            $ca->assign("message", Lang::trans("forwardingtogateway"));
                                                                                            $ca->assign("code", $paymentbutton);
                                                                                            $ca->assign("invoiceid", (int) $invoice->getID());
                                                                                            $ca->output();
                                                                                            exit;
                                                                                        } else {
                                                                                            $smartyvalues["subtotal"] = formatCurrency($subtotal);
                                                                                            $smartyvalues["credit"] = $credit ? formatCurrency($credit) : "";
                                                                                            $smartyvalues["tax"] = $tax ? formatCurrency($tax) : "";
                                                                                            $smartyvalues["tax2"] = $tax2 ? formatCurrency($tax2) : "";
                                                                                            $smartyvalues["partialpayments"] = $partialpayments ? formatCurrency($partialpayments) : "";
                                                                                            $smartyvalues["total"] = formatCurrency($total - $partialpayments);
                                                                                            $smartyvalues["invoiceitems"] = $invoiceitems;
                                                                                            $gatewayslist = showPaymentGatewaysList(array(), $legacyClient->getID());
                                                                                            $smartyvalues["gateways"] = $gatewayslist;
                                                                                            $smartyvalues["defaultgateway"] = key($gatewayslist);
                                                                                            $smartyvalues["taxname1"] = "";
                                                                                            $smartyvalues["taxrate1"] = "";
                                                                                            $smartyvalues["taxname2"] = "";
                                                                                            $smartyvalues["taxrate2"] = "";
                                                                                            if (WHMCS\Config\Setting::getValue("TaxEnabled")) {
                                                                                                $taxdata = getTaxRate(1, $legacyClient->getClientModel()->state, $legacyClient->getClientModel()->country);
                                                                                                $taxdata2 = getTaxRate(2, $legacyClient->getClientModel()->state, $legacyClient->getClientModel()->country);
                                                                                                $smartyvalues["taxname1"] = $taxdata["name"];
                                                                                                $smartyvalues["taxrate1"] = $taxdata["rate"];
                                                                                                $smartyvalues["taxname2"] = $taxdata2["name"];
                                                                                                $smartyvalues["taxrate2"] = $taxdata2["rate"];
                                                                                            }
                                                                                            $ca->addOutputHookFunction("ClientAreaPageMassPay");
                                                                                        }
                                                                                    } else {
                                                                                        if ($action == "quotes") {
                                                                                            checkContactPermission("quotes");
                                                                                            $ca->setDisplayTitle(Lang::trans("quotestitle"));
                                                                                            $ca->setTagLine(Lang::trans("quotesdesc"));
                                                                                            $ca->setTemplate("clientareaquotes");
                                                                                            require ROOTDIR . "/includes/quotefunctions.php";
                                                                                            $result = select_query("tblquotes", "COUNT(*)", array("userid" => $legacyClient->getID()));
                                                                                            $data = mysql_fetch_array($result);
                                                                                            $numitems = $data[0];
                                                                                            list($orderby, $sort, $limit) = clientAreaTableInit("quote", "id", "DESC", $numitems);
                                                                                            if (!in_array($orderby, array("id", "date", "duedate", "total", "stage"))) {
                                                                                                $orderby = "validuntil";
                                                                                            }
                                                                                            $smartyvalues["orderby"] = $orderby;
                                                                                            $smartyvalues["sort"] = strtolower($sort);
                                                                                            $quoteStatus = array("Delivered" => "0", "Accepted" => "0");
                                                                                            $quotes = array();
                                                                                            $result = select_query("tblquotes", "", array("userid" => $legacyClient->getID(), "stage" => array("sqltype" => "NEQ", "value" => "Draft")), $orderby, $sort, $limit);
                                                                                            while ($data = mysql_fetch_assoc($result)) {
                                                                                                $data["normalisedDateCreated"] = $data["datecreated"];
                                                                                                $data["datecreated"] = fromMySQLDate($data["datecreated"], 0, 1);
                                                                                                $data["normalisedValidUntil"] = $data["validuntil"];
                                                                                                $data["validuntil"] = fromMySQLDate($data["validuntil"], 0, 1);
                                                                                                $data["normalisedLastModified"] = $data["lastmodified"];
                                                                                                $data["lastmodified"] = fromMySQLDate($data["lastmodified"], 0, 1);
                                                                                                $data["stageClass"] = WHMCS\View\Helper::generateCssFriendlyClassName($data["stage"]);
                                                                                                $data["stage"] = getQuoteStageLang($data["stage"]);
                                                                                                $quoteStatus[$data["stage"]]++;
                                                                                                $quotes[] = $data;
                                                                                            }
                                                                                            $smartyvalues["quotes"] = $quotes;
                                                                                            $smartyvalues["quotestatus"] = $quoteStatus;
                                                                                            $smartyvalues = array_merge($smartyvalues, clientAreaTablePageNav($numitems));
                                                                                            $ca->addOutputHookFunction("ClientAreaPageQuotes");
                                                                                        } else {
                                                                                            if ($action == "bulkdomain") {
                                                                                                checkContactPermission("managedomains");
                                                                                                $ca->setTemplate("bulkdomainmanagement");
                                                                                                if (empty($domids)) {
                                                                                                    redir("action=domains&error=noDomainsSelected#");
                                                                                                }
                                                                                                $domainids = "";
                                                                                                foreach ($domids as $domid) {
                                                                                                    $domainids .= (int) $domid . ",";
                                                                                                }
                                                                                                $domainids = substr($domainids, 0, -1);
                                                                                                $queryfilter = "userid=" . (int) $legacyClient->getID() . " AND id IN (" . $domainids . ")";
                                                                                                $storedDomains = $domainids = $errors = array();
                                                                                                $result = select_query("tbldomains", "id,domain", $queryfilter, "domain", "ASC");
                                                                                                while ($data = mysql_fetch_assoc($result)) {
                                                                                                    $domainids[] = $data["id"];
                                                                                                    $storedDomains[] = $data["domain"];
                                                                                                }
                                                                                                if (!count($domainids)) {
                                                                                                    redir("action=domains&error=noDomainsSelected#");
                                                                                                }
                                                                                                $queryfilter2 = $queryfilter . " AND status != \"Active\"";
                                                                                                $numNonActiveDomains = get_query_val("tbldomains", "COUNT(\"id\")", $queryfilter2);
                                                                                                if ($numNonActiveDomains != 0) {
                                                                                                    redir("action=domains&error=nonActiveDomainsSelected#");
                                                                                                }
                                                                                                if (!$update) {
                                                                                                    if ($nameservers) {
                                                                                                        $update = "nameservers";
                                                                                                    } else {
                                                                                                        if ($autorenew) {
                                                                                                            $update = "autorenew";
                                                                                                        } else {
                                                                                                            if ($reglock) {
                                                                                                                $update = "reglock";
                                                                                                            } else {
                                                                                                                if ($contactinfo) {
                                                                                                                    $update = "contactinfo";
                                                                                                                } else {
                                                                                                                    if ($renew) {
                                                                                                                        $update = "renew";
                                                                                                                    }
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                }
                                                                                                switch ($update) {
                                                                                                    case "nameservers":
                                                                                                        $ca->setDisplayTitle(Lang::trans("domainmanagens"));
                                                                                                        break;
                                                                                                    case "autorenew":
                                                                                                        $ca->setDisplayTitle(Lang::trans("domainautorenewstatus"));
                                                                                                        break;
                                                                                                    case "reglock":
                                                                                                        $ca->setDisplayTitle(Lang::trans("domainreglockstatus"));
                                                                                                        break;
                                                                                                    case "contactinfo":
                                                                                                        $ca->setDisplayTitle(Lang::trans("domaincontactinfoedit"));
                                                                                                        break;
                                                                                                    default:
                                                                                                        redir();
                                                                                                }
                                                                                                $smartyvalues["domainids"] = $domainids;
                                                                                                $smartyvalues["domains"] = $storedDomains;
                                                                                                $smartyvalues["update"] = $update;
                                                                                                $smartyvalues["save"] = $save;
                                                                                                $currpage = $_SERVER["PHP_SELF"] . "?action=bulkdomain";
                                                                                                $ca->addToBreadCrumb("clientarea.php?action=domains", $whmcs->get_lang("clientareanavdomains"));
                                                                                                if ($update == "nameservers") {
                                                                                                    $ca->addToBreadCrumb($currpage, $whmcs->get_lang("domainmanagens"));
                                                                                                    if ($save) {
                                                                                                        check_token();
                                                                                                        foreach ($domainids as $domainid) {
                                                                                                            $data = get_query_vals("tbldomains", "domain,registrar", array("id" => $domainid, "userid" => $legacyClient->getID()));
                                                                                                            $domain = $data["domain"];
                                                                                                            $registrar = $data["registrar"];
                                                                                                            $domainparts = explode(".", $domain, 2);
                                                                                                            $params = array();
                                                                                                            $params["domainid"] = $domainid;
                                                                                                            list($params["sld"], $params["tld"]) = $domainparts;
                                                                                                            $params["registrar"] = $registrar;
                                                                                                            if ($nschoice == "default") {
                                                                                                                $params = RegGetDefaultNameservers($params, $domain);
                                                                                                            } else {
                                                                                                                $params["ns1"] = $ns1;
                                                                                                                $params["ns2"] = $ns2;
                                                                                                                $params["ns3"] = $ns3;
                                                                                                                $params["ns4"] = $ns4;
                                                                                                                $params["ns5"] = $ns5;
                                                                                                            }
                                                                                                            $values = RegSaveNameservers($params);
                                                                                                            if (!function_exists($registrar . "_SaveNameservers")) {
                                                                                                                $errors[] = $domain . " " . Lang::trans("domaincannotbemanaged");
                                                                                                            }
                                                                                                            if ($values["error"]) {
                                                                                                                $errors[] = $domain . " - " . $values["error"];
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                } else {
                                                                                                    if ($update == "autorenew") {
                                                                                                        $ca->addToBreadCrumb($currpage . "#", $whmcs->get_lang("domainautorenewstatus"));
                                                                                                        if ($save) {
                                                                                                            check_token();
                                                                                                            foreach ($domainids as $domainid) {
                                                                                                                if ($whmcs->get_req_var("enable")) {
                                                                                                                    update_query("tbldomains", array("donotrenew" => ""), array("id" => $domainid, "userid" => $legacyClient->getID()));
                                                                                                                } else {
                                                                                                                    disableAutoRenew($domainid);
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                    } else {
                                                                                                        if ($update == "reglock") {
                                                                                                            $ca->addToBreadCrumb($currpage . "#", $whmcs->get_lang("domainreglockstatus"));
                                                                                                            if ($save) {
                                                                                                                check_token();
                                                                                                                foreach ($domainids as $domainid) {
                                                                                                                    $data = get_query_vals("tbldomains", "domain,registrar", array("id" => $domainid, "userid" => $legacyClient->getID()));
                                                                                                                    $domain = $data["domain"];
                                                                                                                    $registrar = $data["registrar"];
                                                                                                                    $domainparts = explode(".", $domain, 2);
                                                                                                                    $params = array();
                                                                                                                    $params["domainid"] = $domainid;
                                                                                                                    list($params["sld"], $params["tld"]) = $domainparts;
                                                                                                                    $params["registrar"] = $registrar;
                                                                                                                    $newlockstatus = $_POST["enable"] ? "locked" : "unlocked";
                                                                                                                    $params["lockenabled"] = $newlockstatus;
                                                                                                                    $values = RegSaveRegistrarLock($params);
                                                                                                                    if (!function_exists($registrar . "_SaveRegistrarLock")) {
                                                                                                                        $errors[] = $domain . " " . Lang::trans("domaincannotbemanaged");
                                                                                                                    }
                                                                                                                    if ($values["error"]) {
                                                                                                                        $errors[] = $domain . " - " . $values["error"];
                                                                                                                    }
                                                                                                                }
                                                                                                            }
                                                                                                        } else {
                                                                                                            if ($update == "contactinfo") {
                                                                                                                if (!is_array($domainids) || count($domainids) <= 0) {
                                                                                                                    exit("Invalid Access Attempt");
                                                                                                                }
                                                                                                                $ca->addToBreadCrumb($currpage . "#", $whmcs->get_lang("domaincontactinfoedit"));
                                                                                                                if ($save) {
                                                                                                                    check_token();
                                                                                                                    $domainToUpdate = new WHMCS\Domains();
                                                                                                                    $wc = $whmcs->get_req_var("wc");
                                                                                                                    $contactdetails = $whmcs->get_req_var("contactdetails");
                                                                                                                    foreach ($wc as $wc_key => $wc_val) {
                                                                                                                        if ($wc_val == "contact") {
                                                                                                                            $selctype = $sel[$wc_key][0];
                                                                                                                            $selcid = $selctype == "c" ? substr($sel[$wc_key], 1) : "";
                                                                                                                            $tmpContactDetails = $legacyClient->getDetails($selcid);
                                                                                                                            $contactdetails[$wc_key] = $domainToUpdate->buildWHOISSaveArray($tmpContactDetails);
                                                                                                                        }
                                                                                                                    }
                                                                                                                    foreach ($domainids as $domainid) {
                                                                                                                        $domainToUpdate = new WHMCS\Domains();
                                                                                                                        $domain_data = $domainToUpdate->getDomainsDatabyID($domainid);
                                                                                                                        if (!$domain_data) {
                                                                                                                            redir("action=domains", "clientarea.php");
                                                                                                                        }
                                                                                                                        $success = $domainToUpdate->moduleCall("SaveContactDetails", array("contactdetails" => foreignChrReplace($contactdetails)));
                                                                                                                        if (!$success) {
                                                                                                                            if ($domainToUpdate->getLastError() == "Function not found") {
                                                                                                                                $errors[] = $domain . " " . Lang::trans("domaincannotbemanaged");
                                                                                                                            } else {
                                                                                                                                $errors[] = $domainToUpdate->getLastError();
                                                                                                                            }
                                                                                                                        }
                                                                                                                    }
                                                                                                                }
                                                                                                                $smartyvalues["contacts"] = $legacyClient->getContactsWithAddresses();
                                                                                                                $domainToFetch = new WHMCS\Domains();
                                                                                                                $domain_data = $domainToFetch->getDomainsDatabyID($domainids[0]);
                                                                                                                if (!$domain_data) {
                                                                                                                    redir("action=domains", "clientarea.php");
                                                                                                                }
                                                                                                                $success = $domainToFetch->moduleCall("GetContactDetails");
                                                                                                                if ($success) {
                                                                                                                    $smartyvalues["contactdetails"] = $domainToFetch->getModuleReturn();
                                                                                                                }
                                                                                                            } else {
                                                                                                                if ($update == "renew") {
                                                                                                                    redir("gid=renewals", "cart.php");
                                                                                                                } else {
                                                                                                                    redir("action=domains");
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                }
                                                                                                $smartyvalues["errors"] = $errors;
                                                                                                $ca->addOutputHookFunction("ClientAreaPageBulkDomainManagement");
                                                                                            } else {
                                                                                                if ($action == "domainaddons") {
                                                                                                    check_token();
                                                                                                    $ca->setTemplate("clientareadomainaddons");
                                                                                                    $domainid = $domainData["id"];
                                                                                                    $domain = $domainData["domain"];
                                                                                                    if (!$domainid) {
                                                                                                        redir();
                                                                                                    }
                                                                                                    $smartyvalues["domainid"] = $domainid;
                                                                                                    $smartyvalues["domain"] = $domainData["domain"];
                                                                                                    $ca->addToBreadCrumb("clientarea.php?action=domaindetails&id=" . $domainData["id"], $domainData["domain"]);
                                                                                                    $ca->addToBreadCrumb("#", $whmcs->get_lang("clientareahostingaddons"));
                                                                                                    $domainparts = explode(".", $domainData["domain"], 2);
                                                                                                    $result = select_query("tblpricing", "", array("type" => "domainaddons", "currency" => $currency["id"], "relid" => 0));
                                                                                                    $pricingdata = mysql_fetch_array($result);
                                                                                                    $domaindnsmanagementprice = $pricingdata["msetupfee"];
                                                                                                    $domainemailforwardingprice = $pricingdata["qsetupfee"];
                                                                                                    $domainidprotectionprice = $pricingdata["ssetupfee"];
                                                                                                    $ca->assign("addonspricing", array("dnsmanagement" => formatCurrency($domaindnsmanagementprice), "emailforwarding" => formatCurrency($domainemailforwardingprice), "idprotection" => formatCurrency($domainidprotectionprice)));
                                                                                                    if ($disable) {
                                                                                                        $smartyvalues["action"] = "disable";
                                                                                                        $smartyvalues["addon"] = $disable;
                                                                                                        $where = array();
                                                                                                        $where["id"] = $domainData["id"];
                                                                                                        $where["userid"] = $legacyClient->getID();
                                                                                                        if ($disable == "dnsmanagement") {
                                                                                                            if (!$domainData["dnsmanagement"]) {
                                                                                                                redir();
                                                                                                            }
                                                                                                            if ($confirm) {
                                                                                                                check_token();
                                                                                                                update_query("tbldomains", array("dnsmanagement" => "", "recurringamount" => "-=" . $domaindnsmanagementprice), $where);
                                                                                                                $smartyvalues["success"] = true;
                                                                                                            }
                                                                                                        } else {
                                                                                                            if ($disable == "emailfwd") {
                                                                                                                if (!$domainData["emailforwarding"]) {
                                                                                                                    redir();
                                                                                                                }
                                                                                                                if ($confirm) {
                                                                                                                    check_token();
                                                                                                                    update_query("tbldomains", array("emailforwarding" => "", "recurringamount" => "-=" . $domainemailforwardingprice), $where);
                                                                                                                    $smartyvalues["success"] = true;
                                                                                                                }
                                                                                                            } else {
                                                                                                                if ($disable == "idprotect") {
                                                                                                                    if (!$domainData["idprotection"]) {
                                                                                                                        redir();
                                                                                                                    }
                                                                                                                    if ($confirm) {
                                                                                                                        check_token();
                                                                                                                        update_query("tbldomains", array("idprotection" => "", "recurringamount" => "-=" . $domainidprotectionprice), $where);
                                                                                                                        $domainparts = explode(".", $domain, 2);
                                                                                                                        $params = array();
                                                                                                                        $params["domainid"] = $domainData["id"];
                                                                                                                        list($params["sld"], $params["tld"]) = $domainparts;
                                                                                                                        $params["regperiod"] = $domainData["registrationperiod"];
                                                                                                                        $params["registrar"] = $domainData["registrar"];
                                                                                                                        $params["regtype"] = $domainData["type"];
                                                                                                                        $values = RegIDProtectToggle($params);
                                                                                                                        if ($values["error"]) {
                                                                                                                            $smartyvalues["error"] = true;
                                                                                                                        } else {
                                                                                                                            $smartyvalues["success"] = true;
                                                                                                                        }
                                                                                                                    }
                                                                                                                } else {
                                                                                                                    if ($id) {
                                                                                                                        redir("action=domaindetails&id=" . $id);
                                                                                                                    } else {
                                                                                                                        redir();
                                                                                                                    }
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                    if ($buy) {
                                                                                                        $smartyvalues["action"] = "buy";
                                                                                                        $smartyvalues["addon"] = $buy;
                                                                                                        $paymentmethod = getClientsPaymentMethod($legacyClient->getID());
                                                                                                        $domaintax = $whmcs->get_config("TaxDomains") ? 1 : 0;
                                                                                                        $invdesc = "";
                                                                                                        if ($buy == "dnsmanagement") {
                                                                                                            if ($confirm) {
                                                                                                                $invdesc = Lang::trans("domainaddons") . " (" . Lang::trans("domainaddonsdnsmanagement") . ") - " . $domain . " - 1 " . Lang::trans("orderyears");
                                                                                                                $invamt = $domaindnsmanagementprice;
                                                                                                                $addontype = "DNS";
                                                                                                            }
                                                                                                        } else {
                                                                                                            if ($buy == "emailfwd") {
                                                                                                                if ($confirm) {
                                                                                                                    $invdesc = Lang::trans("domainaddons") . " (" . Lang::trans("domainemailforwarding") . ") - " . $domain . " - 1 " . Lang::trans("orderyears");
                                                                                                                    $invamt = $domainemailforwardingprice;
                                                                                                                    $addontype = "EMF";
                                                                                                                }
                                                                                                            } else {
                                                                                                                if ($buy == "idprotect") {
                                                                                                                    if ($confirm) {
                                                                                                                        $invdesc = Lang::trans("domainaddons") . " (" . Lang::trans("domainidprotection") . ") - " . $domain . " - 1 " . Lang::trans("orderyears");
                                                                                                                        $invamt = $domainidprotectionprice;
                                                                                                                        $addontype = "IDP";
                                                                                                                    }
                                                                                                                } else {
                                                                                                                    if ($id) {
                                                                                                                        redir("action=domaindetails&id=" . $id);
                                                                                                                    } else {
                                                                                                                        redir();
                                                                                                                    }
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                        if ($invdesc) {
                                                                                                            check_token();
                                                                                                            insert_query("tblinvoiceitems", array("userid" => $legacyClient->getID(), "type" => "DomainAddon" . $addontype, "relid" => $domainid, "description" => $invdesc, "amount" => $invamt, "taxed" => $domaintax, "duedate" => "now()", "paymentmethod" => $paymentmethod));
                                                                                                            if (!function_exists("createInvoices")) {
                                                                                                                require ROOTDIR . "/includes/processinvoices.php";
                                                                                                            }
                                                                                                            $invoiceid = createInvoices($legacyClient->getID());
                                                                                                            if ($invoiceid) {
                                                                                                                redir("id=" . $invoiceid, "viewinvoice.php");
                                                                                                            }
                                                                                                            redir();
                                                                                                        }
                                                                                                    }
                                                                                                    $ca->addOutputHookFunction("ClientAreaPageDomainAddons");
                                                                                                } else {
                                                                                                    if ($action == "kbsearch") {
                                                                                                        $knowledgebaseController = new WHMCS\Knowledgebase\Controller\Knowledgebase();
                                                                                                        $request = Zend\Diactoros\ServerRequestFactory::fromGlobals();
                                                                                                        $ca = $knowledgebaseController->search($request);
                                                                                                        (new Zend\Diactoros\Response\SapiEmitter())->emit($ca);
                                                                                                        exit;
                                                                                                    }
                                                                                                    redir();
                                                                                                }
                                                                                            }
                                                                                        }
                                                                                    }
                                                                                }
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
switch ($action) {
    case "":
        $sidebarName = "clientHome";
        break;
    case "details":
    case "creditcard":
    case "contacts":
    case "addcontact":
    case "changepw":
    case "security":
    case "emails":
        $sidebarName = "clientView";
        break;
    case "hosting":
    case "products":
    case "services":
        $sidebarName = "serviceList";
        break;
    case "productdetails":
    case "cancel":
        Menu::addContext("service", $serviceModel);
        $sidebarName = "serviceView";
        break;
    case "domains":
        $sidebarName = "domainList";
        break;
    case "domaindetails":
    case "domaincontacts":
    case "domaindns":
    case "domainemailforwarding":
    case "domaingetepp":
    case "domainregisterns":
    case "domainaddons":
        Menu::addContext("domain", $domainModel);
        $sidebarName = "domainView";
        break;
    case "addfunds":
        $sidebarName = "clientAddFunds";
        break;
    case "invoices":
    case "masspay":
        $sidebarName = "invoiceList";
        break;
    case "quotes":
        $sidebarName = "clientQuoteList";
        break;
    default:
        $sidebarName = "clientHome";
        break;
}
Menu::primarySidebar($sidebarName);
Menu::secondarySidebar($sidebarName);
(new Zend\Diactoros\Response\SapiEmitter())->emit($ca);

?>
