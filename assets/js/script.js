// School Inventory System JavaScript

// Global Variables
let currentModal = null;

// Initialize the system when document is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeSystem();
    setupEventListeners();
});

// System Initialization
function initializeSystem() {
    // Initialize tooltips
    initializeTooltips();
    
    // Initialize form validations
    initializeFormValidations();
    
    // Load initial data if needed
    loadInitialData();
    
    // Set current date in date fields
    setCurrentDate();
}

// Setup Event Listeners
function setupEventListeners() {
    // Modal close events
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-backdrop')) {
            closeCurrentModal();
        }
    });
    
    // Escape key to close modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && currentModal) {
            closeCurrentModal();
        }
    });
    
    // Form submission handlers
    setupFormHandlers();
    
    // Search functionality
    setupSearchFunctionality();
}

// Modal Management
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        currentModal = modal;
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        
        // Focus on first input in modal
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }
}

function closeCurrentModal() {
    if (currentModal) {
        currentModal.classList.add('hidden');
        currentModal = null;
        document.body.style.overflow = 'auto';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
        if (currentModal === modal) {
            currentModal = null;
        }
        document.body.style.overflow = 'auto';
    }
}

// Loading Management
function showLoading(button) {
    const originalText = button.innerHTML;
    button.innerHTML = '<div class="loading-spinner"></div> กรุณารอสักครู่...';
    button.disabled = true;
    
    return function() {
        button.innerHTML = originalText;
        button.disabled = false;
    };
}

function showFullPageLoading() {
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'fullpage-loading';
    loadingDiv.className = 'fixed inset-0 bg-white bg-opacity-75 flex items-center justify-center z-50';
    loadingDiv.innerHTML = `
        <div class="text-center">
            <div class="loading-spinner mx-auto mb-4" style="width: 40px; height: 40px; border-width: 4px;"></div>
            <p class="text-gray-600">กำลังโหลด...</p>
        </div>
    `;
    document.body.appendChild(loadingDiv);
}

function hideFullPageLoading() {
    const loadingDiv = document.getElementById('fullpage-loading');
    if (loadingDiv) {
        loadingDiv.remove();
    }
}

// Notification System
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    const typeClasses = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        warning: 'bg-yellow-500',
        info: 'bg-blue-500'
    };
    
    notification.className = `fixed top-4 right-4 ${typeClasses[type]} text-white px-6 py-3 rounded-lg shadow-lg notification z-50`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${getNotificationIcon(type)} mr-2"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

function getNotificationIcon(type) {
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    return icons[type] || 'fa-info-circle';
}

// Confirmation Dialog
function showConfirmation(message, confirmCallback, cancelCallback = null) {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center';
    modal.innerHTML = `
        <div class="relative bg-white rounded-lg shadow-lg max-w-md w-full mx-4">
            <div class="p-6">
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">ຢືນຢັນການດຳເນີນການ</h3>
                    <p class="text-gray-600 mb-6">${message}</p>
                </div>
                <div class="flex justify-center space-x-3">
                    <button type="button" onclick="closeConfirmation()" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">
                        ยกเลิก
                    </button>
                    <button type="button" onclick="handleConfirm()" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                        ตกลง
                    </button>
                </div>
            </div>
        </div>
    `;
    
    modal.confirmCallback = confirmCallback;
    modal.cancelCallback = cancelCallback;
    
    document.body.appendChild(modal);
    currentModal = modal;
    
    window.closeConfirmation = function() {
        if (cancelCallback) cancelCallback();
        modal.remove();
        currentModal = null;
    };
    
    window.handleConfirm = function() {
        confirmCallback();
        modal.remove();
        currentModal = null;
    };
}

// Form Validation
function initializeFormValidations() {
    // Add real-time validation to forms
    const forms = document.querySelectorAll('form[needs-validation]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                showNotification('กรุณากรอกข้อมูลให้ครบถ้วนและถูกต้อง', 'error');
            }
        });
    });
}

function validateForm(form) {
    let isValid = true;
    const requiredFields = form.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            markFieldInvalid(field);
            isValid = false;
        } else {
            markFieldValid(field);
        }
    });
    
    // Custom validations
    const emailFields = form.querySelectorAll('input[type="email"]');
    emailFields.forEach(field => {
        if (field.value && !isValidEmail(field.value)) {
            markFieldInvalid(field);
            isValid = false;
        }
    });
    
    const numberFields = form.querySelectorAll('input[type="number"]');
    numberFields.forEach(field => {
        if (field.hasAttribute('min') && field.value < parseFloat(field.getAttribute('min'))) {
            markFieldInvalid(field);
            isValid = false;
        }
        if (field.hasAttribute('max') && field.value > parseFloat(field.getAttribute('max'))) {
            markFieldInvalid(field);
            isValid = false;
        }
    });
    
    return isValid;
}

function markFieldInvalid(field) {
    field.classList.add('border-red-500');
    field.classList.remove('border-green-500');
}

function markFieldValid(field) {
    field.classList.remove('border-red-500');
    field.classList.add('border-green-500');
}

function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Search Functionality
function setupSearchFunctionality() {
    const searchInputs = document.querySelectorAll('.search-input');
    searchInputs.forEach(input => {
        input.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = this.closest('.table-container').querySelector('tbody');
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });
}

// Data Export
function exportToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    rows.forEach(row => {
        const rowData = [];
        const cells = row.querySelectorAll('th, td');
        
        cells.forEach(cell => {
            // Remove action buttons and icons
            if (!cell.querySelector('button') && !cell.querySelector('i')) {
                rowData.push(`"${cell.textContent.trim()}"`);
            }
        });
        
        if (rowData.length > 0) {
            csv.push(rowData.join(','));
        }
    });
    
    const csvContent = csv.join('\n');
    downloadCSV(csvContent, filename);
}

function downloadCSV(content, filename) {
    const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    
    if (link.download !== undefined) {
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}

// Print Functionality
function printElement(elementId) {
    const element = document.getElementById(elementId);
    const originalContents = document.body.innerHTML;
    
    document.body.innerHTML = element.innerHTML;
    window.print();
    document.body.innerHTML = originalContents;
    window.location.reload();
}

// Utility Functions
function setCurrentDate() {
    const dateFields = document.querySelectorAll('input[type="date"]');
    const today = new Date().toISOString().split('T')[0];
    
    dateFields.forEach(field => {
        if (!field.value) {
            field.value = today;
        }
    });
}

function formatNumber(number) {
    return new Intl.NumberFormat('th-TH').format(number);
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('th-TH', {
        style: 'currency',
        currency: 'THB'
    }).format(amount);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    });
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Local Storage Management
const storage = {
    set: function(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            console.error('Error saving to localStorage:', e);
            return false;
        }
    },
    
    get: function(key) {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : null;
        } catch (e) {
            console.error('Error reading from localStorage:', e);
            return null;
        }
    },
    
    remove: function(key) {
        try {
            localStorage.removeItem(key);
            return true;
        } catch (e) {
            console.error('Error removing from localStorage:', e);
            return false;
        }
    },
    
    clear: function() {
        try {
            localStorage.clear();
            return true;
        } catch (e) {
            console.error('Error clearing localStorage:', e);
            return false;
        }
    }
};

// Initialize tooltips (if using any tooltip library)
function initializeTooltips() {
    // If using a tooltip library, initialize it here
    // Example for tippy.js:
    // tippy('[data-tippy-content]');
}

// Load initial data if needed
function loadInitialData() {
    // Load any initial data required for the application
    // This could be user preferences, cached data, etc.
}

// Setup form handlers
function setupFormHandlers() {
    // Add any specific form submission handlers here
}

// Error handling
window.addEventListener('error', function(e) {
    console.error('Global error:', e.error);
    showNotification('เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่', 'error');
});

// Export functions for global use
window.openModal = openModal;
window.closeModal = closeModal;
window.showLoading = showLoading;
window.showNotification = showNotification;
window.showConfirmation = showConfirmation;
window.exportToCSV = exportToCSV;
window.printElement = printElement;
window.storage = storage;