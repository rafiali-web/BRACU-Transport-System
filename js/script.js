/**
 * Main JavaScript file for University Bus Booking System
 * Contains all interactive functionality
 */   
//YASIN
document.addEventListener('DOMContentLoaded', function() {
    // Modal functionality
    const busModal = document.getElementById('busModal');
    const loginModal = document.getElementById('loginModal');
    const closeModalButtons = document.querySelectorAll('.close-modal');
    const routeCards = document.querySelectorAll('.route-card');
    const tabs = document.querySelectorAll('.tab');
    const tabContents = document.querySelectorAll('.tab-content');


    




    
    // Open bus modal when a route card is clicked
    routeCards.forEach(card => {
        card.addEventListener('click', () => {
            busModal.style.display = 'flex';
        });
    });
    
    // Close modal when X is clicked
    closeModalButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            busModal.style.display = 'none';
            loginModal.style.display = 'none';
        });
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', (event) => {
        if (event.target === busModal) {
            busModal.style.display = 'none';
        }
        if (event.target === loginModal) {
            loginModal.style.display = 'none';
        }
    });
    
    // Tab functionality
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const tabId = tab.getAttribute('data-tab');
            
            // Remove active class from all tabs and contents
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            // Add active class to clicked tab and corresponding content
            tab.classList.add('active');
            document.getElementById(`${tabId}-tab`).classList.add('active');
        });
    });
    
    // Seat selection functionality
    const seats = document.querySelectorAll('.seat.available');
    seats.forEach(seat => {
        seat.addEventListener('click', function() {
            seats.forEach(s => s.classList.remove('selected'));
            this.classList.add('selected');
        });
    });
    
    // Confirm booking button
    const confirmBookingBtn = document.getElementById('confirmBookingBtn');
    if (confirmBookingBtn) {
        confirmBookingBtn.addEventListener('click', () => {
            alert('Booking confirmed! This would be implemented with PHP and MySQL database updates.');
            busModal.style.display = 'none';
        });
    }
    
    // Select seat button
    const selectSeatBtn = document.getElementById('selectSeatBtn');
    if (selectSeatBtn) {
        selectSeatBtn.addEventListener('click', () => {
            const selectedSeat = document.querySelector('.seat.selected');
            if (selectedSeat) {
                alert(`Seat ${selectedSeat.textContent} selected. This would be implemented with PHP and MySQL.`);
            } else {
                alert('Please select a seat first.');
            }
        });
    }
    
    // Payment method selection
    const paymentMethod = document.getElementById('payment_method');
    const creditCardFields = document.getElementById('credit_card_fields');
    
    if (paymentMethod && creditCardFields) {
        paymentMethod.addEventListener('change', function() {
            if (this.value === 'credit_card' || this.value === 'debit_card') {
                creditCardFields.style.display = 'block';
            } else {
                creditCardFields.style.display = 'none';
            }
        });
    }
    
    // Form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = 'var(--danger)';
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });
});


/**
 * Enhanced UI Interactions and Animations
 * for University Bus Booking System
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all animations
    initAnimations();
    
    // Add smooth scrolling for anchor links
    initSmoothScrolling();
    
    // Add loading states for buttons
    initButtonLoaders();
    
    // Add form validation animations
    initFormAnimations();
    
    // Add page transition awareness
    initPageTransitions();
});

// Initialize animations
function initAnimations() {
    // Animate elements when they come into view
    const animatedElements = document.querySelectorAll('.card, .route-card, .dashboard-card');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    
    animatedElements.forEach(element => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';
        element.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(element);
    });
}

// Smooth scrolling for anchor links
function initSmoothScrolling() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
}

// Button loading states
function initButtonLoaders() {
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.classList.contains('no-loader')) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                submitBtn.disabled = true;
            }
        });
    });
}

// Form validation animations
function initFormAnimations() {
    document.querySelectorAll('.form-control').forEach(input => {
        input.addEventListener('invalid', function() {
            this.style.animation = 'shake 0.5s ease';
            setTimeout(() => {
                this.style.animation = '';
            }, 500);
        });
        
        input.addEventListener('blur', function() {
            if (!this.checkValidity()) {
                this.style.borderColor = 'var(--danger)';
            }
        });
        
        input.addEventListener('input', function() {
            if (this.checkValidity()) {
                this.style.borderColor = 'var(--secondary)';
            }
        });
    });
}

// Page transition awareness
function initPageTransitions() {
    // Add loading state for page transitions
    document.querySelectorAll('a:not([target="_blank"])').forEach(link => {
        link.addEventListener('click', function(e) {
            if (this.href && !this.classList.contains('no-transition')) {
                e.preventDefault();
                document.body.style.opacity = '0.7';
                document.body.style.transition = 'opacity 0.3s ease';
                
                setTimeout(() => {
                    window.location.href = this.href;
                }, 300);
            }
        });
    });
}

// Additional animation helper functions
function shakeElement(element) {
    element.style.animation = 'shake 0.5s ease';
    setTimeout(() => {
        element.style.animation = '';
    }, 500);
}

function highlightElement(element) {
    element.style.boxShadow = '0 0 0 3px var(--secondary)';
    setTimeout(() => {
        element.style.boxShadow = '';
    }, 1000);
}

// Add shake animation for invalid inputs
const style = document.createElement('style');
style.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }
`;
document.head.appendChild(style);



// Enhanced modal closing with animation
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.opacity = '0';
        modal.style.transform = 'scale(0.9)';
        setTimeout(() => {
            modal.style.display = 'none';
            modal.style.opacity = '1';
            modal.style.transform = 'scale(1)';
        }, 300);
    }
}

// Enhanced modal opening with animation
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.style.opacity = '1';
            modal.style.transform = 'scale(1)';
        }, 10);
    }
}





// Smooth scroll to elements with offset
function smoothScrollTo(element, offset = 0) {
    const elementPosition = element.getBoundingClientRect().top;
    const offsetPosition = elementPosition + window.pageYOffset - offset;
    
    window.scrollTo({
        top: offsetPosition,
        behavior: 'smooth'
    });
}

// Advanced intersection observer for complex animations
function initAdvancedAnimations() {
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                // Add different animations based on element class
                if (entry.target.classList.contains('reveal-up')) {
                    entry.target.classList.add('active');
                }
                if (entry.target.classList.contains('reveal-scale')) {
                    entry.target.classList.add('active');
                }
                if (entry.target.classList.contains('morph-shape')) {
                    entry.target.style.animationPlayState = 'running';
                }
            }
        });
    }, observerOptions);
    
    // Observe all animatable elements
    document.querySelectorAll('.reveal-up, .reveal-scale, .morph-shape').forEach(el => {
        observer.observe(el);
    });
}

// Advanced form interactions
function initAdvancedFormInteractions() {
    const formInputs = document.querySelectorAll('.form-control');
    
    formInputs.forEach(input => {
        // Add floating label effect
        if (input.value !== '') {
            input.classList.add('has-value');
        }
        
        input.addEventListener('focus', () => {
            input.parentElement.classList.add('focused');
        });
        
        input.addEventListener('blur', () => {
            input.parentElement.classList.remove('focused');
            if (input.value !== '') {
                input.classList.add('has-value');
            } else {
                input.classList.remove('has-value');
            }
        });
    });
}

// Page load animations sequence
function animatePageLoad() {
    const elements = document.querySelectorAll('.card, .route-card, .dashboard-card');
    
    elements.forEach((el, index) => {
        setTimeout(() => {
            el.style.opacity = '1';
            el.style.transform = 'translateY(0) scale(1)';
        }, index * 100);
    });
}

// Initialize all advanced effects
document.addEventListener('DOMContentLoaded', function() {
    initCursorEffects();
    initAdvancedAnimations();
    initAdvancedFormInteractions();
    animatePageLoad();
});



// Swipe gestures for mobile
function initSwipeGestures() {
    let touchStartX = 0;
    let touchStartY = 0;
    
    document.addEventListener('touchstart', (e) => {
        touchStartX = e.changedTouches[0].screenX;
        touchStartY = e.changedTouches[0].screenY;
    });
    
    document.addEventListener('touchend', (e) => {
        const touchEndX = e.changedTouches[0].screenX;
        const touchEndY = e.changedTouches[0].screenY;
        const diffX = touchEndX - touchStartX;
        const diffY = touchEndY - touchStartY;
        
        // Horizontal swipe (min 50px movement)
        if (Math.abs(diffX) > 50 && Math.abs(diffX) > Math.abs(diffY)) {
            if (diffX > 0) {
                // Swipe right - maybe go back?
                if (window.history.length > 1) {
                    window.history.back();
                }
            } else {
                // Swipe left - maybe go forward?
                window.history.forward();
            }
        }
    });
}

// Initialize gestures
initSwipeGestures();




// Animated counter for your dashboard
function animateValue(element, start, end, duration) {
    let startTimestamp = null;
    const step = (timestamp) => {
        if (!startTimestamp) startTimestamp = timestamp;
        const progress = Math.min((timestamp - startTimestamp) / duration, 1);
        element.textContent = Math.floor(progress * (end - start) + start);
        if (progress < 1) {
            window.requestAnimationFrame(step);
        }
    };
    window.requestAnimationFrame(step);
}

// Use it like this when elements come into view
document.querySelectorAll('.dashboard-card h3').forEach(card => {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const value = parseInt(entry.target.textContent);
                animateValue(entry.target, 0, value, 2000);
                observer.unobserve(entry.target);
            }
        });
    });
    observer.observe(card);
});



// Animated counter for your dashboard
function animateValue(element, start, end, duration) {
    let startTimestamp = null;
    const step = (timestamp) => {
        if (!startTimestamp) startTimestamp = timestamp;
        const progress = Math.min((timestamp - startTimestamp) / duration, 1);
        element.textContent = Math.floor(progress * (end - start) + start);
        if (progress < 1) {
            window.requestAnimationFrame(step);
        }
    };
    window.requestAnimationFrame(step);
}

// Use it like this when elements come into view
document.querySelectorAll('.dashboard-card h3').forEach(card => {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const value = parseInt(entry.target.textContent);
                animateValue(entry.target, 0, value, 2000);
                observer.unobserve(entry.target);
            }
        });
    });
    observer.observe(card);
});



// Enhanced seat selection with animations
function selectSeat(seatElement) {
    // Remove previous selection with animation
    document.querySelectorAll('.seat.selected').forEach(seat => {
        seat.style.animation = 'seatDeselect 0.3s ease';
        setTimeout(() => {
            seat.classList.remove('selected');
            seat.style.animation = '';
        }, 300);
    });
    
    // Add new selection with animation
    seatElement.classList.add('selected');
    seatElement.style.animation = 'seatSelect 0.5s ease';
    
    // Show confirmation animation
    const confirmation = document.createElement('div');
    confirmation.className = 'selection-confirmation';
    confirmation.innerHTML = '<i class="fas fa-check"></i> Seat Selected!';
    document.body.appendChild(confirmation);
    
    setTimeout(() => {
        confirmation.remove();
    }, 2000);
}



// Simple notification system
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type} notification-popup`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        ${message}
    `;
    
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.classList.add('active');
    }, 10);
    
    // Remove after delay
    setTimeout(() => {
        notification.classList.remove('active');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}


// Seasonal background changes
function applySeasonalTheme() {
    const now = new Date();
    const month = now.getMonth();
    const body = document.body;
    
    body.classList.remove('theme-winter', 'theme-spring', 'theme-summer', 'theme-fall');
    
    if (month >= 11 || month < 2) { // Winter
        body.classList.add('theme-winter');
    } else if (month >= 2 && month < 5) { // Spring
        body.classList.add('theme-spring');
    } else if (month >= 5 && month < 8) { // Summer
        body.classList.add('theme-summer');
    } else { // Fall
        body.classList.add('theme-fall');
    }
}

// Call on page load
applySeasonalTheme();



////

// Function to update departure timers in real-time
// Function to update departure timers in real-time
// SIMPLE COUNTDOWN TIMER - NO TIMEZONE ISSUES
// SIMPLE COUNTDOWN TIMER - USING YOUR EXISTING LIVE TIME
// SIMPLE COUNTDOWN TIMER - USING DEPARTURE TIME (NOT ARRIVAL TIME)
// FIXED TIMER FUNCTION - USING DEPARTURE TIME ONLY
function updateDepartureTimers() {
    const timers = document.querySelectorAll('.departure-timer');
    
    timers.forEach(timer => {
        // THIS SHOULD BE departure_time FROM THE DATA ATTRIBUTE
        const departureTimeStr = timer.getAttribute('data-departure');
        // DEBUG: Log what time we're actually getting
        console.log('Timer using time:', departureTimeStr);
        console.log('Current time:', new Date().toLocaleString());
        
        // Parse MySQL datetime format (YYYY-MM-DD HH:MM:SS)
        const [datePart, timePart] = departureTimeStr.split(' ');
        const [year, month, day] = datePart.split('-');
        const [hours, minutes, seconds] = timePart.split(':');
        
        // Create date object from departure time
        const departureDate = new Date(year, month - 1, day, hours, minutes, seconds);
        const now = new Date();
        
        const timeDiff = departureDate - now;
        
        if (timeDiff <= 0) {
            timer.textContent = 'Departed';
            timer.classList.add('text-danger');
            
            // Disable cancel button if it exists
            const cancelBtn = timer.closest('.booking-card').querySelector('button[type="submit"]');
            if (cancelBtn) {
                cancelBtn.disabled = true;
                cancelBtn.textContent = 'Cancel (Expired)';
                cancelBtn.classList.remove('btn-warning');
                cancelBtn.classList.add('btn-secondary');
            }
            
            return;
        }
        
        const hoursLeft = Math.floor(timeDiff / (1000 * 60 * 60));
        const minutesLeft = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
        const secondsLeft = Math.floor((timeDiff % (1000 * 60)) / 1000);
        
        timer.textContent = `${hoursLeft}h ${minutesLeft}m ${secondsLeft}s`;
        
        // Update refund status dynamically
        if (hoursLeft < 1) {
            const refundElement = timer.closest('.booking-card').querySelector('.text-success');
            if (refundElement) {
                refundElement.textContent = 'No refund available';
                refundElement.classList.remove('text-success');
                refundElement.classList.add('text-danger');
            }
            
            // Update cancel button
            const cancelBtn = timer.closest('.booking-card').querySelector('button[type="submit"]');
            if (cancelBtn && cancelBtn.textContent.includes('Refund')) {
                cancelBtn.disabled = true;
                cancelBtn.textContent = 'Cancel (No Refund)';
                cancelBtn.classList.remove('btn-warning');
                cancelBtn.classList.add('btn-danger');
            }
        }
    });
}

// Update timers every second
setInterval(updateDepartureTimers, 1000);
updateDepartureTimers(); // Initial update

/////


////

// Close modal when clicking X
document.querySelector('.close-modal').addEventListener('click', function() {
    document.getElementById('ticketModal').style.display = 'none';
});

// Close modal when clicking outside
document.getElementById('ticketModal').addEventListener('click', function(event) {
    if (event.target === this) {
        this.style.display = 'none';
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.getElementById('ticketModal').style.display = 'none';
    }
});

function showCommentInput(postId) {
    const quickComment = document.getElementById('quick-comment-' + postId);
    
    // Hide all other quick comment inputs first
    document.querySelectorAll('[id^="quick-comment-"]').forEach(el => {
        if (el.id !== 'quick-comment-' + postId) {
            el.style.display = 'none';
        }
    });
    
    // Toggle the clicked one
    if (quickComment.style.display === 'none') {
        quickComment.style.display = 'block';
        // Focus the input
        const input = quickComment.querySelector('.comment-input');
        if (input) {
            input.focus();
        }
    } else {
        quickComment.style.display = 'none';
    }
}

function toggleComments(postId) {
    const comments = document.getElementById('comments-' + postId);
    if (comments.style.display === 'none') {
        comments.style.display = 'block';
    } else {
        comments.style.display = 'none';
    }
}

function validateComment(form) {
    const input = form.querySelector('.comment-input');
    const comment = input.value.trim();
    
    if (comment.length === 0) {
        alert('Please enter a comment');
        return false;
    }
    
    if (comment.length > 200) {
        alert('Comment is too long! Maximum 200 characters.');
        return false;
    }
    
    return true;
}

function expandComment(element) {
    const fullText = element.getAttribute('data-full');
    element.parentElement.innerHTML = fullText;
}

// Add character counter to all comment inputs
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.comment-input').forEach(input => {
        input.addEventListener('input', function() {
            const counter = this.closest('form').querySelector('.char-counter');
            if (counter) {
                const remaining = this.value.length;
                counter.textContent = remaining + '/200 characters';
                
                if (remaining > 180) {
                    counter.classList.add('near-limit');
                    counter.classList.remove('at-limit');
                } else if (remaining >= 200) {
                    counter.classList.add('at-limit');
                    this.value = this.value.substring(0, 200);
                } else {
                    counter.classList.remove('near-limit', 'at-limit');
                }
            }
        });
    });
});