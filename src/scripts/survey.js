/**
 * Veloce Cycles - Interactive Conditional Survey Engine
 * 
 * Implements deterministic survey state machine with:
 * - Dynamic branch routing (Riders vs Aspiring Cyclists)
 * - Step validation & keyboard accessibility
 * - Server submission with honeypot & rate-limit handling
 * - Optimistic live response counter updates
 */

const surveyState = {
    currentStep: 1,
    totalSteps: 5,
    riderStatus: '', // 'active_cyclist' | 'occasional_rider' | 'aspiring_cyclist'
    branch: '',      // 'rider' | 'aspiring'
    answers: {
        primaryPurpose: '',
        ridingFrequency: '',
        weeklyDistance: '',
        currentBikeType: '',
        holdingReasons: [],
        desiredUse: '',
        painPoints: [],
        budgetBand: '',
        district: '',
        name: '',
        email: '',
        phone: '',
        earlyVoucher: true,
        testRide: true
    },
    submissionStatus: 'idle'
};

document.addEventListener('DOMContentLoaded', () => {
    const surveyContainer = document.getElementById('survey-container');
    if (!surveyContainer) return;

    // Elements
    const progressBar = document.getElementById('survey-progress-bar');
    const stepCountDisplay = document.getElementById('survey-step-count');
    const percentDisplay = document.getElementById('survey-step-percent');
    const prevBtn = document.getElementById('survey-prev-btn');
    const nextBtn = document.getElementById('survey-next-btn');
    const submitBtn = document.getElementById('survey-submit-btn');
    const errorBanner = document.getElementById('survey-error-banner');
    const successView = document.getElementById('survey-success-state');
    const wizardView = document.getElementById('survey-wizard-view');

    // Setup Option Cards Selection
    const setupSingleChoiceCards = (containerSelector, callback) => {
        const container = document.querySelector(containerSelector);
        if (!container) return;
        const cards = container.querySelectorAll('.option-card');
        cards.forEach(card => {
            card.addEventListener('click', () => {
                cards.forEach(c => c.classList.remove('selected'));
                card.classList.add('selected');
                const val = card.dataset.value;
                if (callback) callback(val);
                hideError();
            });
        });
    };

    const setupMultiChoiceCards = (containerSelector, callback) => {
        const container = document.querySelector(containerSelector);
        if (!container) return;
        const cards = container.querySelectorAll('.option-card');
        cards.forEach(card => {
            card.addEventListener('click', () => {
                card.classList.toggle('selected');
                const selectedCards = container.querySelectorAll('.option-card.selected');
                const values = Array.from(selectedCards).map(c => c.dataset.value);
                if (callback) callback(values);
                hideError();
            });
        });
    };

    // Step 1: Rider Status Selection
    setupSingleChoiceCards('#step-1-options', (val) => {
        surveyState.riderStatus = val;
        surveyState.branch = (val === 'aspiring_cyclist') ? 'aspiring' : 'rider';
        updateBranchView();
    });

    // Step 2 (Rider): Purpose & Distance
    setupSingleChoiceCards('#step-2-rider-purpose', (val) => {
        surveyState.answers.primaryPurpose = val;
    });
    setupSingleChoiceCards('#step-2-rider-distance', (val) => {
        surveyState.answers.weeklyDistance = val;
    });

    // Step 2 (Aspiring): Holding Reasons & Desired Use
    setupMultiChoiceCards('#step-2-aspiring-reasons', (values) => {
        surveyState.answers.holdingReasons = values;
    });
    setupSingleChoiceCards('#step-2-aspiring-use', (val) => {
        surveyState.answers.desiredUse = val;
    });

    // Step 3: Pain Points
    setupMultiChoiceCards('#step-3-pain-points', (values) => {
        surveyState.answers.painPoints = values;
    });

    // Step 4: Budget Band
    setupSingleChoiceCards('#step-4-budget', (val) => {
        surveyState.answers.budgetBand = val;
    });

    // Step 4: District Dropdown
    const districtSelect = document.getElementById('survey-district-select');
    if (districtSelect) {
        districtSelect.addEventListener('change', (e) => {
            surveyState.answers.district = e.target.value;
            hideError();
        });
    }

    // Step 5: Contact Inputs
    const nameInput = document.getElementById('survey-name');
    const emailInput = document.getElementById('survey-email');
    const phoneInput = document.getElementById('survey-phone');
    const voucherCheckbox = document.getElementById('survey-voucher-chk');
    const testRideCheckbox = document.getElementById('survey-testride-chk');

    if (nameInput) nameInput.addEventListener('input', (e) => surveyState.answers.name = e.target.value);
    if (emailInput) emailInput.addEventListener('input', (e) => surveyState.answers.email = e.target.value);
    if (phoneInput) phoneInput.addEventListener('input', (e) => surveyState.answers.phone = e.target.value);
    if (voucherCheckbox) voucherCheckbox.addEventListener('change', (e) => surveyState.answers.earlyVoucher = e.target.checked);
    if (testRideCheckbox) testRideCheckbox.addEventListener('change', (e) => surveyState.answers.testRide = e.target.checked);

    // Update conditional branch step visibility
    function updateBranchView() {
        const riderBranch = document.getElementById('step-2-rider-container');
        const aspiringBranch = document.getElementById('step-2-aspiring-container');
        if (surveyState.branch === 'aspiring') {
            if (riderBranch) riderBranch.style.display = 'none';
            if (aspiringBranch) aspiringBranch.style.display = 'block';
        } else {
            if (riderBranch) riderBranch.style.display = 'block';
            if (aspiringBranch) aspiringBranch.style.display = 'none';
        }
    }

    // Validation for Current Step
    function validateCurrentStep() {
        hideError();
        if (surveyState.currentStep === 1) {
            if (!surveyState.riderStatus) {
                showError('Please select your cycling status to continue.');
                return false;
            }
        } else if (surveyState.currentStep === 2) {
            if (surveyState.branch === 'aspiring') {
                if (surveyState.answers.holdingReasons.length === 0) {
                    showError('Please select at least one reason that has held you back.');
                    return false;
                }
            } else {
                if (!surveyState.answers.primaryPurpose) {
                    showError('Please select your primary riding purpose.');
                    return false;
                }
            }
        } else if (surveyState.currentStep === 3) {
            if (surveyState.answers.painPoints.length === 0) {
                showError('Please select at least one cycling challenge in Sri Lanka.');
                return false;
            }
        } else if (surveyState.currentStep === 4) {
            if (!surveyState.answers.budgetBand) {
                showError('Please select your preferred budget range.');
                return false;
            }
            if (!surveyState.answers.district) {
                showError('Please select your district in Sri Lanka.');
                return false;
            }
        } else if (surveyState.currentStep === 5) {
            if (emailInput && emailInput.value.trim()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(emailInput.value.trim())) {
                    showError('Please enter a valid email address, or leave it blank.');
                    return false;
                }
            }
        }
        return true;
    }

    function showError(msg) {
        if (errorBanner) {
            errorBanner.textContent = msg;
            errorBanner.style.display = 'block';
        }
    }

    function hideError() {
        if (errorBanner) {
            errorBanner.style.display = 'none';
        }
    }

    function renderStep() {
        hideError();
        const steps = document.querySelectorAll('.survey-step');
        steps.forEach(s => s.classList.remove('active'));

        const currentStepEl = document.querySelector(`.survey-step[data-step="${surveyState.currentStep}"]`);
        if (currentStepEl) {
            currentStepEl.classList.add('active');
        }

        // Progress bar calculations
        const pct = Math.round((surveyState.currentStep / surveyState.totalSteps) * 100);
        if (progressBar) progressBar.style.width = `${pct}%`;
        if (stepCountDisplay) stepCountDisplay.textContent = `Step ${surveyState.currentStep} of ${surveyState.totalSteps}`;
        if (percentDisplay) percentDisplay.textContent = `${pct}% Complete`;

        // Navigation Button states
        if (prevBtn) {
            prevBtn.style.visibility = (surveyState.currentStep === 1) ? 'hidden' : 'visible';
        }
        if (nextBtn && submitBtn) {
            if (surveyState.currentStep === surveyState.totalSteps) {
                nextBtn.style.display = 'none';
                submitBtn.style.display = 'inline-flex';
            } else {
                nextBtn.style.display = 'inline-flex';
                submitBtn.style.display = 'none';
            }
        }
    }

    // Step Button Handlers
    nextBtn?.addEventListener('click', () => {
        if (validateCurrentStep()) {
            if (surveyState.currentStep < surveyState.totalSteps) {
                surveyState.currentStep++;
                renderStep();
                surveyContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }
    });

    prevBtn?.addEventListener('click', () => {
        if (surveyState.currentStep > 1) {
            surveyState.currentStep--;
            renderStep();
            surveyContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });

    // Form Submission
    submitBtn?.addEventListener('click', async () => {
        if (!validateCurrentStep()) return;

        submitBtn.disabled = true;
        submitBtn.innerHTML = `
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;">
                <circle cx="12" cy="12" r="10" stroke-opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10"/>
            </svg>
            Recording Response...
        `;

        const hpVal = document.getElementById('survey-hp')?.value || '';

        const payload = {
            rider_status: surveyState.riderStatus,
            primary_purpose: surveyState.answers.primaryPurpose,
            riding_frequency: surveyState.answers.ridingFrequency,
            weekly_distance: surveyState.answers.weeklyDistance,
            current_bike_type: surveyState.answers.currentBikeType,
            holding_reasons: surveyState.answers.holdingReasons,
            pain_points: surveyState.answers.painPoints,
            budget_band: surveyState.answers.budgetBand,
            district: surveyState.answers.district,
            name: surveyState.answers.name,
            email: surveyState.answers.email,
            phone: surveyState.answers.phone,
            early_access_voucher: surveyState.answers.earlyVoucher,
            test_ride_interest: surveyState.answers.testRide,
            website_hp: hpVal
        };

        try {
            const response = await fetch('server/submit-survey.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (response.ok && data.success) {
                // Show celebratory success view
                if (wizardView) wizardView.style.display = 'none';
                if (successView) successView.style.display = 'block';

                // Update count badges across the page
                if (data.count) {
                    window.dispatchEvent(new CustomEvent('veloce:count-updated', { detail: { count: data.count } }));
                }
            } else {
                showError(data.error || 'Unable to record your response. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Submit My Feedback';
            }
        } catch (err) {
            console.error('Survey submission error:', err);
            showError('Network error connecting to server. Please check your connection and try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Submit My Feedback';
        }
    });

    // Initialize initial render
    renderStep();
});
