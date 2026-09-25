/**
 * Veloce Cycles — Central Brand & Social Configuration
 * 
 * Edit your brand name, legal name, taglines, and social links here.
 * Changes in this file automatically propagate across the entire website.
 */

const VELOCE_CONFIG = {
    // Brand Nomenclature
    brandShort: "Cycles",
    brandFullName: "SL Cycles",
    brandTagline: "Engineered for Sri Lankan Roads. Built for Pure Speed.",

    // Social Channels & Contact Endpoints
    social: {
        facebook: "https://facebook.com/cycleslk",
        instagram: "https://instagram.com/cycleslk",
        email: "cycles@example.com",
        phone: "+94 77 000 0000",
        address: "Colombo 06, Sri Lanka"
    },

    // Promotional & Voucher Details
    voucherCode: "VELOCE10K-VIP",
    voucherAmount: "LKR 10,000"
};

// Export for module or global scope
if (typeof module !== 'undefined' && module.exports) {
    module.exports = VELOCE_CONFIG;
}
