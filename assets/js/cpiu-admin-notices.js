/**
 * CPIU Admin Notices JavaScript
 * Handles admin notice interactions and AJAX functionality
 */

/* global jQuery, ajaxurl, cpiu_admin_notices */
'use strict';

(function($) {
	var CPIU_AdminNotices = {
		
		/**
		 * Initialize admin notices functionality
		 */
		init: function() {
			this.bindEvents();
		},
		
		/**
		 * Bind all event handlers
		 */
		bindEvents: function() {
			// Unbind existing handlers to prevent duplicates
			$(document).off('click.cpiu-notices');
			
			// Bind new handlers with namespace
			$(document).on('click.cpiu-notices', '[data-cpiu-action="dismiss-installation"]', this.dismissInstallationNotice);
			$(document).on('click.cpiu-notices', '[data-cpiu-action="set-data-preference"]', this.setDataPreference);
			$(document).on('click.cpiu-notices', '[data-cpiu-action="dismiss-data-notice"]', this.dismissDataNotice);
		},
		
		/**
		 * Dismiss installation notice
		 */
		dismissInstallationNotice: function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var $notice = $button.closest('#cpiu-installation-notice');
			
			$.post(window.ajaxurl, {
				action: 'cpiu_dismiss_installation_notice',
				nonce: window.cpiu_admin_notices.dismiss_nonce
			}).done(function() {
				$notice.fadeOut();
			}).fail(function() {
				console.error('Failed to dismiss installation notice');
			});
		},
		
		/**
		 * Set data preference for uninstallation
		 */
		setDataPreference: function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var preference = $button.data('preference');
			var $notice = $button.closest('#cpiu-data-management-notice');
			
			$.post(window.ajaxurl, {
				action: 'cpiu_set_data_preference',
				preference: preference,
				nonce: window.cpiu_admin_notices.data_preference_nonce
			}).done(function(response) {
				if (response.success) {
					$notice.fadeOut();
				}
			}).fail(function() {
				console.error('Failed to set data preference');
			});
		},
		
		/**
		 * Dismiss data management notice
		 */
		dismissDataNotice: function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var $notice = $button.closest('#cpiu-data-management-notice');
			
			$.post(window.ajaxurl, {
				action: 'cpiu_dismiss_data_notice',
				nonce: window.cpiu_admin_notices.dismiss_data_nonce
			}).done(function() {
				$notice.fadeOut();
			}).fail(function() {
				console.error('Failed to dismiss data notice');
			});
		}
	};
	
	// Initialize when document is ready
	$(document).ready(function() {
		CPIU_AdminNotices.init();
	});
	
})(jQuery);