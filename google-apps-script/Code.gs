/**
 * Veloce Cycles - Google Apps Script Survey Webhook Receiver
 * 
 * INSTRUCTIONS FOR CLIENT:
 * 1. Open Google Sheets (https://sheets.google.com) and create a new Spreadsheet named "Veloce Cycles - Survey Responses".
 * 2. In the menu, click Extensions > Apps Script.
 * 3. Replace all code with this file's content.
 * 4. Click "Deploy" > "New deployment".
 * 5. Select type: "Web app".
 * 6. Set description: "Veloce Cycles Webhook".
 * 7. Set "Execute as": "Me (your account)".
 * 8. Set "Who has access": "Anyone" (allows your Veloce server to submit responses securely).
 * 9. Click "Deploy", authorize permissions, and copy the Web App URL (ends with /exec).
 * 10. Paste this Web App URL into your server's `private/config.php` file under 'google_sheets_webhook_url'.
 */

function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  
  try {
    var doc = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = doc.getActiveSheet();
    
    // Parse JSON payload from Veloce server
    var data;
    if (e.postData && e.postData.contents) {
      data = JSON.parse(e.postData.contents);
    } else {
      data = e.parameter;
    }
    
    // Auto-create headers if sheet is newly initialized
    if (sheet.getLastRow() === 0) {
      var headers = [
        "Submission ID",
        "Timestamp",
        "Rider Status",
        "Primary Purpose",
        "Riding Frequency",
        "Weekly Distance",
        "Current Bike Type",
        "Holding Reasons (Aspiring)",
        "Pain Points",
        "Budget Band",
        "District",
        "Name",
        "Email",
        "Phone",
        "Early Voucher Lead",
        "Test Ride Lead",
        "Masked IP"
      ];
      sheet.appendRow(headers);
      sheet.getRange(1, 1, 1, headers.length).setFontWeight("bold").setBackground("#111827").setFontColor("#FFFFFF");
      sheet.setFrozenRows(1);
    }
    
    var holdingText = Array.isArray(data.holding_reasons) ? data.holding_reasons.join(", ") : (data.holding_reasons || "");
    var painPointsText = Array.isArray(data.pain_points) ? data.pain_points.join(", ") : (data.pain_points || "");
    
    var row = [
      data.id || "",
      data.timestamp || new Date().toISOString(),
      data.rider_status || "",
      data.primary_purpose || "",
      data.riding_frequency || "",
      data.weekly_distance || "",
      data.current_bike_type || "",
      holdingText,
      painPointsText,
      data.budget_band || "",
      data.district || "",
      data.name || "",
      data.email || "",
      data.phone || "",
      data.early_access_voucher ? "Yes" : "No",
      data.test_ride_interest ? "Yes" : "No",
      data.ip_masked || ""
    ];
    
    sheet.appendRow(row);
    
    return ContentService
      .createTextOutput(JSON.stringify({ "result": "success", "row": sheet.getLastRow() }))
      .setMimeType(ContentService.MimeType.JSON);
      
  } catch (error) {
    return ContentService
      .createTextOutput(JSON.stringify({ "result": "error", "error": error.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
      
  } finally {
    lock.releaseLock();
  }
}

function doGet(e) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  var count = Math.max(0, sheet.getLastRow() - 1);
  return ContentService
    .createTextOutput(JSON.stringify({ "status": "active", "total_responses": count }))
    .setMimeType(ContentService.MimeType.JSON);
}
