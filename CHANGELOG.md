# Nuvei Checkout Plugin for OpenCart 3

---

# 2.1.0
```
    * Removed the plugin option to auto-close or not the APM popup.
    * Added additional parameter to the Simply Connect on the QA site.
    * Save notification when come Sale/Auth DMN and there is no Ordert for its Transaction.
    * Added an option to turn off/on the auto-void logic.
    * Added an option to modify Simply Connect style.
    * Fixed the check for the recurring products and Guest users.
```

# 2.0
```
	* Replace the modes with events.
```

# 1.12
```
    * Fix the plugin behavior when product with non-Nuvei rebilling plan is added to the Cart.
```

# 1.11
```
    * Pass sourceApplication to Simply Connect.
```

# 1.10
```
    * Add option to mask/unmask user details in the log.
    * Try to destroy checkout object before creating it.
    * Fix for the sourceApplication parameter.
```

# 1.9-p1
```
    * Added Tag SDK URL for test cases.
```

# 1.9
```
    * Fix for the "SDK translations" setting example.
    * Added locale for Gpay button.
```

# 1.8
```
    * Removed the updateOrder reques on SDK pre-payment event.
    * Changes into the logic who check for plugin updates.
    * Check if Order total and currency are correct when DMN come.
    * Added plugin version into plugin settings Help Tools.
    * Disable DCC when Order total is Zero.
    * Added the amount and the currency into Order's notes.
    * Save DMN Note for Pending DMNs.
```

# 1.7
```
    * Fixed the bug into DMN logic.
    * Removed tha Auto-Void logic.
    * Removed return code 400 to the Cashier, when the plugin did not find an OC Order by the DMN data.
```

# 1.6
```
    * Changed Sanbox REST API endpoint.
    * Removed plugin option to change SDK version.
    * Added new company logo to the admin.
    * Added SimplyConnect themes.
    * Added option how to open APM window.
    * Added Auto-Void logic.
    * Allways pass userTokenId into OpenOrder request.
    * Pass timestamp instead date-time in the requests.
    * Return code 400 to the Cashier, when the plugin did not find an OC Order by the DMN data.
    * Merchant credentials are trimmed after get them.
    * Changes in clientUniqueId parameter - in it pass the Order ID and custom generated string.
```

# 1.5
```
    * Changed sourceApplication and webMasterId parameters value.
    * Remove required flag from plugin "plan_id" setting.
```

# 1.4
```
    * Force transaction type to Auth when Order total amount is 0.
```

# 1.3
```
    * Fix for the case where updateOrder can not update the userTokenId parameter.
    * Do not pass billing and user details to Checkout request.
    * Added Cancel Subscription button into Order details page > Nuvei Actions group.
```

# 1.2
```
    * Fix for the missing insufficient funds message.
    * Fix for the wrong Nuvei Documentation links into the plugin settings.
```

# 1.1
```
    * Enabled OC recurring support.
```

# 1.0
```
    * Replace WebSDK with Checkout SDK.
    * Show the Order ID into the Success page URL.
    * Use Catalog currency for all Nuvei actions over the Order.
    * Added plugin version checker. It checks for upgrade every 7th day.
```
