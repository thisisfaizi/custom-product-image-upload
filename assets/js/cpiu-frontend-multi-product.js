/**
 * CPIU Frontend Multi-Product JavaScript
 * 
 * Enhanced frontend functionality for multi-product image upload
 * 
 * @package Custom_Product_Image_Upload
 * @since 1.2.0
 */

/* global Cropper, cpiu_params */

document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // Check if required dependencies are loaded
    if (typeof Cropper === 'undefined' || typeof cpiu_params === 'undefined') {
        console.error('CPIU Error: Cropper.js or cpiu_params not loaded.');
        return;
    }

    // Get DOM elements
    const uploadButton = document.getElementById('cpiu-open-upload-modal');
    const uploadModal = document.getElementById('cpiu-upload-modal');
    const closeUploadModal = document.getElementById('cpiu-close-upload-modal');
    const doneButton = document.getElementById('cpiu-done-button');
    const fileInput = document.getElementById('cpiu_custom_images');
    const previewContainer = document.getElementById('cpiu-image-preview');
    const externalPreviewContainer = document.getElementById('cpiu-image-preview-external');
    const errorContainer = document.getElementById('cpiu-error-container');
    const cropperModal = document.getElementById('cpiu-cropper-modal');
    const imageToCrop = document.getElementById('cpiu-image-to-crop');
    const saveCropButton = document.getElementById('cpiu-save-cropped-image');
    const closeCropButton = document.getElementById('cpiu-close-cropper-modal');
    const addToCartButton = document.querySelector('form.cart .single_add_to_cart_button');
    const uploadProgressContainer = document.getElementById('cpiu-upload-progress');
    const progressBar = document.getElementById('cpiu-progress-bar');
    const progressText = document.getElementById('cpiu-progress-text');
    const uploadLoadingModal = document.getElementById('cpiu-upload-loading');
    const uploadStatusText = document.getElementById('cpiu-upload-status');

    // Debug: Check which elements exist
    console.log('CPIU Debug - uploadButton:', uploadButton);
    console.log('CPIU Debug - uploadModal:', uploadModal);
    console.log('CPIU Debug - closeUploadModal:', closeUploadModal);
    console.log('CPIU Debug - fileInput:', fileInput);
    console.log('CPIU Debug - previewContainer:', previewContainer);
    console.log('CPIU Debug - errorContainer:', errorContainer);

    // Validate essential elements exist
    if (!uploadButton || !uploadModal || !closeUploadModal) {
        console.error('CPIU Error: Modal elements missing. uploadButton:', !!uploadButton, 'uploadModal:', !!uploadModal, 'closeUploadModal:', !!closeUploadModal);
        return;
    }

    if (!fileInput || !previewContainer || !errorContainer) {
        console.error('CPIU Error: Upload elements missing. fileInput:', !!fileInput, 'previewContainer:', !!previewContainer, 'errorContainer:', !!errorContainer);
        return;
    }

    // External preview container is optional
    if (!externalPreviewContainer) {
        console.warn('CPIU Warning: External preview container not found. Preview will only show in modal.');
    }

    // Initialize variables
    let cropperInstance = null;
    let currentImageElement = null;
    let uploadedImageData = []; // Array of { originalSrc, currentSrc, element, wrapper } for images
    let uploadedPdfData = []; // Array of { file, name, wrapper } for PDF files
    let currentShapeAsString = 'none'; // Default shape

    // PDF support flags
    const hasPdf = cpiu_params.has_pdf === true || cpiu_params.has_pdf === '1';
    const pdfUploadAction = cpiu_params.pdf_upload_action || 'cpiu_upload_pdf_files';

    // Store original button text (the last text node, keeping the SVG intact)
    const originalUploadBtnText = uploadButton
        ? (uploadButton.lastChild && uploadButton.lastChild.nodeType === Node.TEXT_NODE
            ? uploadButton.lastChild.textContent.trim()
            : uploadButton.textContent.trim())
        : '';

    /**
     * Update the upload button label based on what types are queued.
     * Shows "Upload File" when any PDF is in the queue, otherwise
     * restores the original admin-configured button text.
     */
    function updateUploadButtonLabel() {
        if (!uploadButton) return;

        const hasPdfQueued = uploadedPdfData.length > 0;
        const newText = hasPdfQueued
            ? (cpiu_params.text_upload_file || 'Upload File')
            : originalUploadBtnText;

        // Replace only the last text node so the SVG icon is preserved
        if (uploadButton.lastChild && uploadButton.lastChild.nodeType === Node.TEXT_NODE) {
            uploadButton.lastChild.textContent = ' ' + newText;
        } else {
            // Fallback: just update the whole text content (no SVG case)
            uploadButton.textContent = newText;
        }
    }

    /**
     * Sync image preview between modal and external container
     */
    function syncImagePreview() {
        if (!externalPreviewContainer) return;

        // Clear external preview
        externalPreviewContainer.innerHTML = '';

        const totalUploaded = uploadedImageData.length + uploadedPdfData.length;

        if (totalUploaded === 0) {
            // Show no images message
            const noImagesMsg = document.createElement('div');
            noImagesMsg.className = 'cpiu-no-images-message';
            noImagesMsg.textContent = cpiu_params.text_no_images || 'No images uploaded yet. Click "<?php echo $upload_button_text; ?>" above to add your files.';
            externalPreviewContainer.appendChild(noImagesMsg);
        } else {
            // Show uploaded images
            uploadedImageData.forEach((imageData, index) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'cpiu-image-wrapper';

                const imgElement = document.createElement('img');
                imgElement.src = imageData.currentSrc;
                imgElement.alt = `Uploaded image ${index + 1}`;
                imgElement.className = 'cpiu-preview-image';
                imgElement.title = cpiu_params.text_click_to_crop || 'Click to crop';

                const deleteBtn = document.createElement('button');
                deleteBtn.innerHTML = '&times;';
                deleteBtn.type = 'button';
                deleteBtn.className = 'cpiu-delete-btn';
                deleteBtn.ariaLabel = cpiu_params.text_delete_image || 'Delete image';
                deleteBtn.title = cpiu_params.text_delete_image || 'Delete image';

                // Add click handlers
                imgElement.addEventListener('click', () => handleOpenCropper(imageData));
                deleteBtn.addEventListener('click', () => handleDeleteImage(imageData));

                wrapper.appendChild(imgElement);
                wrapper.appendChild(deleteBtn);
                externalPreviewContainer.appendChild(wrapper);
            });

            // Show PDF cards in external preview
            uploadedPdfData.forEach((pdfData) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'cpiu-image-wrapper cpiu-pdf-preview-wrapper';
                wrapper.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:8px;background:#f9f9f9;border:1px dashed #ccc;border-radius:4px;';

                const icon = document.createElement('span');
                icon.textContent = '📄';
                icon.style.cssText = 'font-size:32px;';

                const nameLabel = document.createElement('span');
                nameLabel.textContent = pdfData.name;
                nameLabel.style.cssText = 'font-size:11px;word-break:break-all;text-align:center;max-width:80px;';

                const deleteBtn = document.createElement('button');
                deleteBtn.innerHTML = '&times;';
                deleteBtn.type = 'button';
                deleteBtn.className = 'cpiu-delete-btn';
                deleteBtn.ariaLabel = cpiu_params.text_delete_image || 'Delete';
                deleteBtn.title = cpiu_params.text_delete_image || 'Delete';
                deleteBtn.addEventListener('click', () => handleDeletePdf(pdfData));

                wrapper.appendChild(icon);
                wrapper.appendChild(nameLabel);
                wrapper.appendChild(deleteBtn);
                externalPreviewContainer.appendChild(wrapper);
            });

            // Add crop helper text below thumbnails (only for images)
            if (uploadedImageData.length > 0) {
                const cropHint = document.createElement('p');
                cropHint.className = 'cpiu-external-crop-hint';
                cropHint.innerHTML = '<strong>💡 Tip:</strong> ' + (cpiu_params.text_click_to_crop_hint || 'Click on an image to crop it if needed.');
                externalPreviewContainer.appendChild(cropHint);
            }
        }
    }

    // Modal functionality
    function openUploadModal() {
        console.log('CPIU Debug - Opening modal');
        uploadModal.classList.add('show');
        document.body.classList.add('cpiu-modal-open', 'cpiu-no-scroll');
    }

    /**
     * Generate Shape Selector HTML
     */
    function generateShapeSelector() {
        // Shapes configuration
        const shapes = [
            { id: 'none', label: 'None', icon: '🚫', aspectRatio: NaN },
            { id: 'square', label: 'Square', icon: '■', aspectRatio: 1 },
            { id: 'rectangle', label: 'Rectangle', icon: '▭', aspectRatio: NaN },
            { id: 'circle', label: 'Circle', icon: '●', aspectRatio: 1 },
            { id: 'heart', label: 'Heart', icon: '♥', aspectRatio: 480 / 438.82 }
        ];

        const container = document.createElement('div');
        container.className = 'cpiu-shape-selector-container';

        const label = document.createElement('span');
        label.className = 'cpiu-shape-label';
        label.textContent = 'Select Shape: ';
        container.appendChild(label);

        const buttonsContainer = document.createElement('div');
        buttonsContainer.className = 'cpiu-shape-buttons';

        shapes.forEach(shape => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `cpiu-shape-btn ${shape.id === 'none' ? 'active' : ''}`;
            btn.dataset.shape = shape.id;
            btn.dataset.aspectRatio = shape.aspectRatio;
            btn.innerHTML = `<span class="cpiu-shape-icon">${shape.icon}</span> ${shape.label}`;
            btn.title = `Crop as ${shape.label}`;

            // Add event listener immediately
            btn.addEventListener('click', (e) => handleShapeSelection(e, shape));

            buttonsContainer.appendChild(btn);
        });

        container.appendChild(buttonsContainer);
        return container;
    }

    /**
     * Handle Shape Selection
     */
    function handleShapeSelection(event, shape) {
        // Update active button state
        const allBtns = document.querySelectorAll('.cpiu-shape-btn');
        allBtns.forEach(b => b.classList.remove('active'));

        // Handle click on icon or text span
        const clickedBtn = event.target.closest('.cpiu-shape-btn');
        if (clickedBtn) {
            clickedBtn.classList.add('active');
        }

        // Update Cropper aspect ratio
        if (cropperInstance) {
            let newAspectRatio = shape.aspectRatio;

            // If "None" is selected, use the admin configured ratio
            if (shape.id === 'none') {
                newAspectRatio = getAspectRatioFromSetting(croppingRatioSetting);
            }

            cropperInstance.setAspectRatio(newAspectRatio);

            // Update crop box styling for visual feedback
            const cropBox = document.querySelector('.cropper-view-box');
            if (cropBox) {
                // Reset classes
                cropBox.classList.remove('cpiu-shape-circle', 'cpiu-shape-heart');

                // Add specific class for masking preview
                if (shape.id === 'circle') {
                    cropBox.classList.add('cpiu-shape-circle');
                } else if (shape.id === 'heart') {
                    cropBox.classList.add('cpiu-shape-heart');
                }
            }
        }

        // Store current shape for save handler
        currentShapeAsString = shape.id;
    }

    // Modal functionality

    function closeUploadModalFunc() {
        console.log('CPIU Debug - Closing modal');
        uploadModal.classList.remove('show');
        document.body.classList.remove('cpiu-modal-open', 'cpiu-no-scroll');
    }

    // Test modal functionality
    console.log('CPIU Debug - Testing modal functionality');
    console.log('CPIU Debug - Modal element exists:', !!uploadModal);
    console.log('CPIU Debug - Button element exists:', !!uploadButton);

    // Event listeners for modal
    console.log('CPIU Debug - Adding event listeners');
    uploadButton.addEventListener('click', function (e) {
        console.log('CPIU Debug - Upload button clicked');
        e.preventDefault();
        console.log('CPIU Debug - Modal element:', uploadModal);
        console.log('CPIU Debug - Modal classes:', uploadModal.className);
        openUploadModal();
        console.log('CPIU Debug - Modal classes after opening:', uploadModal.className);
    });
    closeUploadModal.addEventListener('click', function (e) {
        console.log('CPIU Debug - Close button clicked');
        e.preventDefault();
        closeUploadModalFunc();
    });

    // Add event listener for Done button
    if (doneButton) {
        doneButton.addEventListener('click', function (e) {
            console.log('CPIU Debug - Done button clicked');
            e.preventDefault();
            closeUploadModalFunc();
        });
    }

    // Close modal when clicking outside
    uploadModal.addEventListener('click', function (e) {
        if (e.target === uploadModal) {
            closeUploadModalFunc();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && uploadModal.classList.contains('show')) {
            closeUploadModalFunc();
        }
    });

    let requiredCount = parseInt(cpiu_params.required_image_count, 10);
    let maxFileSize = parseInt(cpiu_params.max_file_size, 10);
    let allowedTypes = cpiu_params.allowed_types || ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    const resolutionValidation = cpiu_params.resolution_validation || false;
    const minWidth = parseInt(cpiu_params.min_width, 10) || 0;
    const minHeight = parseInt(cpiu_params.min_height, 10) || 0;
    const maxWidth = parseInt(cpiu_params.max_width, 10) || 0;
    const maxHeight = parseInt(cpiu_params.max_height, 10) || 0;

    // Shape validation settings
    const shapeValidationEnabled = cpiu_params.shape_validation_enabled || false;
    const shapeRequirements = cpiu_params.shape_requirements || [];

    // Cropping settings
    const enableShapeCropping = cpiu_params.enable_shape_cropping === '1' || cpiu_params.enable_shape_cropping === true;
    const croppingRatioSetting = cpiu_params.cropping_ratio || 'free';

    /**
     * Parse cropping ratio string to number
     */
    function getAspectRatioFromSetting(setting) {
        if (!setting || setting === 'free') return NaN;

        const parts = setting.split(':');
        if (parts.length === 2) {
            const width = parseFloat(parts[0]);
            const height = parseFloat(parts[1]);
            if (!isNaN(width) && !isNaN(height) && height !== 0) {
                return width / height;
            }
        }
        return NaN; // Default to free if invalid
    }

    // Calculate total required images from shape requirements if enabled
    let effectiveRequiredCount = requiredCount;
    if (shapeValidationEnabled && shapeRequirements.length > 0) {
        effectiveRequiredCount = shapeRequirements.reduce((sum, req) => sum + parseInt(req.quantity_required, 10), 0);
        console.log('CPIU Debug - Shape validation enabled. Total required:', effectiveRequiredCount, 'Shapes:', shapeRequirements);
    }

    // Disable add to cart button initially
    addToCartButton.disabled = true;

    // Add event listeners
    fileInput.addEventListener('change', handleFileSelection);
    saveCropButton.addEventListener('click', handleSaveCrop);
    closeCropButton.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handleCloseCropper();
    });

    /**
     * Handle file selection
     */
    function handleFileSelection(event) {
        const files = event.target.files;
        if (!files || files.length === 0) return;

        // Show progress container
        uploadProgressContainer.classList.add('cpiu-show');
        progressBar.style.width = '0%';
        progressText.textContent = cpiu_params.text_loading;

        let filesProcessed = 0;
        const totalFiles = files.length;
        const newFilesArray = Array.from(files);

        newFilesArray.forEach(file => {
            // Validate file type
            if (!isValidFileType(file)) {
                progressText.textContent = cpiu_params.text_skipped_file.replace('{filename}', file.name);
                showFileTypeError(file.name);
                filesProcessed++;
                updateFileReadProgress(filesProcessed, totalFiles);
                return;
            }

            // Validate file size
            if (file.size > maxFileSize) {
                progressText.textContent = cpiu_params.error_file_size.replace('{filename}', file.name);
                showFileSizeError(file.name);
                filesProcessed++;
                updateFileReadProgress(filesProcessed, totalFiles);
                return;
            }

            // ---- PDF path: no cropping, no resolution validation ----
            if (isPdfFile(file)) {
                const totalCount = uploadedImageData.length + uploadedPdfData.length;
                if (totalCount >= requiredCount) {
                    updateValidationError(cpiu_params.error_more_images);
                    console.warn(`CPIU: Max ${requiredCount} files. Skipped PDF: ${file.name}`);
                    filesProcessed++;
                    updateFileReadProgress(filesProcessed, totalFiles);
                    return;
                }
                addPdfPreview(file);
                filesProcessed++;
                updateFileReadProgress(filesProcessed, totalFiles);
                validateImageCount();
                return;
            }

            // Validate image resolution
            validateImageResolution(file, (isValid, errorMessage) => {
                if (!isValid) {
                    progressText.textContent = errorMessage;
                    showResolutionError(errorMessage);
                    filesProcessed++;
                    updateFileReadProgress(filesProcessed, totalFiles);
                    return;
                }

                // If resolution is valid, proceed with file reading
                const reader = new FileReader();
                reader.onloadstart = () => {
                    progressText.textContent = cpiu_params.text_loading_file.replace('{filename}', file.name);
                };

                reader.onload = (e) => {
                    const totalCount = uploadedImageData.length + uploadedPdfData.length;
                    if (totalCount < requiredCount) {
                        addImagePreview(e.target.result, file.name);
                    } else {
                        updateValidationError(cpiu_params.error_more_images);
                        console.warn(`CPIU: Max ${requiredCount} files reached. Skipped: ${file.name}`);
                    }
                    filesProcessed++;
                    updateFileReadProgress(filesProcessed, totalFiles);
                    validateImageCount();
                };

                reader.onerror = () => {
                    console.error(`CPIU: Error reading ${file.name}`);
                    progressText.textContent = cpiu_params.text_error_loading.replace('{filename}', file.name);
                    filesProcessed++;
                    updateFileReadProgress(filesProcessed, totalFiles);
                };

                reader.readAsDataURL(file);
            });
        });

        // Clear input
        fileInput.value = '';
    }

    /**
     * Validate file type
     */
    function isValidFileType(file) {
        const fileExtension = file.name.split('.').pop().toLowerCase();
        return allowedTypes.includes(fileExtension);
    }

    /**
     * Check if a file is a PDF
     */
    function isPdfFile(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        return ext === 'pdf';
    }

    /**
     * Validate image resolution
     */
    function validateImageResolution(file, callback) {
        if (!resolutionValidation) {
            callback(true, null);
            return;
        }

        const img = new Image();

        img.onload = function () {
            // Clean up object URL
            URL.revokeObjectURL(img.src);

            const width = img.width;
            const height = img.height;
            let errorMessage = null;

            // Check minimum dimensions
            if (minWidth > 0 && width < minWidth) {
                errorMessage = cpiu_params.error_resolution_min_width
                    .replace('{filename}', file.name)
                    .replace('{width}', width)
                    .replace('{min_width}', minWidth);
            } else if (minHeight > 0 && height < minHeight) {
                errorMessage = cpiu_params.error_resolution_min_height
                    .replace('{filename}', file.name)
                    .replace('{height}', height)
                    .replace('{min_height}', minHeight);
            }
            // Check maximum dimensions
            else if (maxWidth > 0 && width > maxWidth) {
                errorMessage = cpiu_params.error_resolution_max_width
                    .replace('{filename}', file.name)
                    .replace('{width}', width)
                    .replace('{max_width}', maxWidth);
            } else if (maxHeight > 0 && height > maxHeight) {
                errorMessage = cpiu_params.error_resolution_max_height
                    .replace('{filename}', file.name)
                    .replace('{height}', height)
                    .replace('{max_height}', maxHeight);
            }

            callback(errorMessage === null, errorMessage);
        };

        img.onerror = function () {
            URL.revokeObjectURL(img.src);
            callback(false, cpiu_params.text_resolution_error || 'Could not load image for resolution validation.');
        };

        // Create object URL for the file
        const objectURL = URL.createObjectURL(file);
        img.src = objectURL;
    }

    /**
     * Update file read progress
     */
    function updateFileReadProgress(loaded, total) {
        if (!progressBar || !progressText) return;

        const percent = total > 0 ? Math.round((loaded / total) * 100) : 0;
        progressBar.style.width = percent + '%';
        progressText.textContent = cpiu_params.text_loaded_progress
            .replace('{loaded}', loaded)
            .replace('{total}', total)
            .replace('{percent}', percent);

        if (loaded >= total) {
            setTimeout(() => {
                if (uploadProgressContainer) {
                    uploadProgressContainer.classList.remove('cpiu-show');
                }
            }, 1500);
        }
    }


    /**
     * Add PDF preview card (no cropping)
     */
    function addPdfPreview(file) {
        if (!previewContainer) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'cpiu-image-wrapper-large cpiu-pdf-card';
        wrapper.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:12px 8px;background:#f7f7f7;border:2px dashed #aaa;border-radius:6px;min-width:90px;position:relative;';

        const icon = document.createElement('span');
        icon.textContent = '📄';
        icon.style.cssText = 'font-size:36px;';

        const nameLabel = document.createElement('span');
        nameLabel.textContent = file.name;
        nameLabel.style.cssText = 'font-size:11px;word-break:break-all;text-align:center;max-width:90px;';

        const nocropNote = document.createElement('span');
        nocropNote.textContent = cpiu_params.text_pdf_no_crop || 'PDF — no cropping';
        nocropNote.style.cssText = 'font-size:10px;color:#888;font-style:italic;text-align:center;';

        const deleteBtn = document.createElement('button');
        deleteBtn.innerHTML = '&times;';
        deleteBtn.type = 'button';
        deleteBtn.className = 'cpiu-delete-btn-large';
        deleteBtn.ariaLabel = cpiu_params.text_delete_image || 'Delete';
        deleteBtn.title = cpiu_params.text_delete_image || 'Delete';

        wrapper.appendChild(icon);
        wrapper.appendChild(nameLabel);
        wrapper.appendChild(nocropNote);
        wrapper.appendChild(deleteBtn);
        previewContainer.appendChild(wrapper);

        const pdfData = { file, name: file.name, wrapper };
        uploadedPdfData.push(pdfData);

        deleteBtn.addEventListener('click', () => handleDeletePdf(pdfData));

        syncImagePreview();
        updateUploadButtonLabel();
    }

    /**
     * Handle deleting a PDF file
     */
    function handleDeletePdf(pdfData) {
        if (!pdfData || !pdfData.wrapper) return;
        pdfData.wrapper.remove();
        uploadedPdfData = uploadedPdfData.filter(d => d !== pdfData);
        validateImageCount();
        syncImagePreview();
        updateUploadButtonLabel();
    }

    /**
     * Add image preview
     */
    function addImagePreview(imageSrc, imageName) {
        if (!previewContainer) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'cpiu-image-wrapper-large';

        const imgElement = document.createElement('img');
        imgElement.src = imageSrc;
        imgElement.alt = imageName;
        imgElement.className = 'cpiu-preview-image-large';
        imgElement.title = cpiu_params.text_click_to_crop || 'Click to crop';

        const deleteBtn = document.createElement('button');
        deleteBtn.innerHTML = '&times;';
        deleteBtn.type = 'button';
        deleteBtn.className = 'cpiu-delete-btn-large';
        deleteBtn.ariaLabel = cpiu_params.text_delete_image || 'Delete image';
        deleteBtn.title = cpiu_params.text_delete_image || 'Delete image';

        wrapper.appendChild(imgElement);
        wrapper.appendChild(deleteBtn);
        previewContainer.appendChild(wrapper);

        const imageData = {
            originalSrc: imageSrc,
            currentSrc: imageSrc,
            element: imgElement,
            wrapper: wrapper,
            name: imageName
        };

        uploadedImageData.push(imageData);

        imgElement.addEventListener('click', () => handleOpenCropper(imageData));
        deleteBtn.addEventListener('click', () => handleDeleteImage(imageData));

        // Sync with external preview
        syncImagePreview();
    }

    /**
     * Handle opening cropper
     */
    function handleOpenCropper(imageData) {
        console.log('CPIU Debug - Opening cropper');
        if (!cropperModal || !imageToCrop || typeof Cropper === 'undefined') {
            console.error('CPIU Error - Cropper elements missing:', {
                cropperModal: !!cropperModal,
                imageToCrop: !!imageToCrop,
                Cropper: typeof Cropper
            });
            return;
        }

        currentImageElement = imageData.element;
        imageToCrop.src = imageData.currentSrc;

        // Use both class and style for maximum compatibility
        cropperModal.classList.add('show');

        // Inject Shape Selector if not already present AND enabled
        const cropperContent = cropperModal.querySelector('.cpiu-cropper-modal-content');
        let existingSelector = cropperContent.querySelector('.cpiu-shape-selector-container');

        if (enableShapeCropping) {
            if (!existingSelector) {
                const shapeSelector = generateShapeSelector();
                const cropperContainer = document.getElementById('cpiu-cropper-container');
                // Insert before the image container
                cropperContent.insertBefore(shapeSelector, cropperContainer);
            } else {
                // Ensure it's visible
                existingSelector.style.display = 'block';
                // Reset to default on re-open
                const noneBtn = existingSelector.querySelector('[data-shape="none"]');
                if (noneBtn) noneBtn.click();
            }
        } else {
            // If shape cropping is disabled, remove or hide the selector
            if (existingSelector) {
                existingSelector.style.display = 'none';
            }
        }

        console.log('CPIU Debug - Cropper modal display set to flex');

        if (cropperInstance) {
            cropperInstance.destroy();
        }

        // Inject Restore Button if not present
        const actionButtons = cropperModal.querySelector('.cpiu-cropper-actions');
        let restoreBtn = document.getElementById('cpiu-restore-crop');
        if (!restoreBtn && actionButtons) {
            restoreBtn = document.createElement('button');
            restoreBtn.type = 'button';
            restoreBtn.id = 'cpiu-restore-crop';
            restoreBtn.className = 'button cpiu-restore-btn';
            restoreBtn.textContent = 'Restore Original';
            restoreBtn.style.marginRight = '10px';

            // Insert before Cancel button (which is usually the last one)
            const cancelBtn = document.getElementById('cpiu-close-cropper-modal');
            actionButtons.insertBefore(restoreBtn, cancelBtn);

            // Add listener
            restoreBtn.addEventListener('click', handleRestoreCrop);
        }

        // Determine initial aspect ratio
        let initialAspectRatio = NaN;

        if (enableShapeCropping) {
            // Default to admin setting because "None" is default now
            initialAspectRatio = getAspectRatioFromSetting(croppingRatioSetting);
        } else {
            // Use the setting
            initialAspectRatio = getAspectRatioFromSetting(croppingRatioSetting);
        }

        // Small delay to ensure modal is visible before initializing cropper
        setTimeout(() => {
            try {
                cropperInstance = new Cropper(imageToCrop, {
                    aspectRatio: initialAspectRatio,
                    viewMode: 1,
                    autoCropArea: 0.8,
                    responsive: true,
                    background: false,
                    modal: false,
                    guides: true,
                    center: true,
                    highlight: false,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: false
                });
                console.log('CPIU Debug - Cropper initialized successfully');
            } catch (error) {
                console.error('CPIU Error - Failed to initialize cropper:', error);
            }
        }, 100);

        if (addToCartButton) {
            addToCartButton.disabled = true;
        }
        updateValidationError('');
    }

    /**
     * Handle saving crop
     */
    function handleSaveCrop(event) {
        event.preventDefault();
        event.stopPropagation();

        if (!cropperInstance || !currentImageElement) {
            console.error("CPIU Error: Cropper/image ref missing.");
            return;
        }

        let canvas = cropperInstance.getCroppedCanvas({
            fillColor: (currentShapeAsString === 'none' || currentShapeAsString === 'square' || currentShapeAsString === 'rectangle') ? '#fff' : 'transparent',
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'medium'
        });

        if (canvas) {
            // Apply shape mask if needed
            if (currentShapeAsString === 'circle' || currentShapeAsString === 'heart') {
                const tempCanvas = document.createElement('canvas');
                tempCanvas.width = canvas.width;
                tempCanvas.height = canvas.height;
                const ctx = tempCanvas.getContext('2d');

                // 1. Draw Mask
                ctx.fillStyle = '#000000'; // Color doesn't matter for masking, opacity does

                if (currentShapeAsString === 'circle') {
                    ctx.beginPath();
                    ctx.arc(tempCanvas.width / 2, tempCanvas.height / 2, tempCanvas.width / 2, 0, 2 * Math.PI);
                    ctx.fill();
                } else if (currentShapeAsString === 'heart') {
                    // SVG Path for heart (viewport 0 0 480 438.82)
                    const heartPath = new Path2D('M438.82 41.18c-54.9-54.9-143.92-54.9-198.82 0-54.9-54.9-143.92-54.9-198.82 0-54.9 54.9-54.9 143.92 0 198.82L240 438.82 438.82 240c54.9-54.9 54.9-143.92 0-198.82Z');

                    ctx.save();
                    // Scale to fit canvas (maintain aspect ratio if needed, or stretch to fill crop box)
                    // The SVG is roughly 480x439.
                    const scaleX = tempCanvas.width / 480;
                    const scaleY = tempCanvas.height / 438.82;
                    // Center the heart
                    ctx.translate(0, (tempCanvas.height - (438.82 * scaleY)) / 2); // vertical centering if needed, but here we fill
                    ctx.scale(scaleX, scaleY);
                    ctx.fill(heartPath);
                    ctx.restore();
                }

                // 2. Composite Source Image (keep source only where destination is opaque)
                ctx.globalCompositeOperation = 'source-in';
                ctx.drawImage(canvas, 0, 0);

                // Update canvas reference
                canvas = tempCanvas;
            }

            // For shapes, we likely want PNG to preserve transparency
            const mimeType = (currentShapeAsString === 'none' || currentShapeAsString === 'square' || currentShapeAsString === 'rectangle') ? 'image/jpeg' : 'image/png';
            const quality = 0.9;

            const croppedImageDataUrl = canvas.toDataURL(mimeType, quality);
            const imageDataIndex = uploadedImageData.findIndex(data => data.element === currentImageElement);

            if (imageDataIndex > -1) {
                uploadedImageData[imageDataIndex].element.src = croppedImageDataUrl;
                uploadedImageData[imageDataIndex].currentSrc = croppedImageDataUrl;

                // Sync with external preview
                syncImagePreview();
            } else {
                console.error("CPIU Error: Could not find image data to update.");
            }

            handleCloseCropper();
        } else {
            console.error("CPIU Error: Could not get cropped canvas.");
            alert(cpiu_params.text_crop_error || 'Could not crop image.');
        }
    }

    /**
     * Handle restoring original image
     */
    function handleRestoreCrop(event) {
        event.preventDefault();

        if (!cropperInstance || !currentImageElement) return;

        const imageData = uploadedImageData.find(data => data.element === currentImageElement);
        if (imageData && imageData.originalSrc) {
            // Replace cropper image with original source
            cropperInstance.replace(imageData.originalSrc);

            // Also update the hidden image ref to ensure if they just save immediately it works
            const imageToCrop = document.getElementById('cpiu-image-to-crop');
            if (imageToCrop) {
                imageToCrop.src = imageData.originalSrc;
            }
        }
    }

    /**
     * Handle closing cropper
     */
    function handleCloseCropper() {
        console.log('CPIU Debug - Closing cropper');
        if (cropperModal) {
            cropperModal.classList.remove('show');
        }

        if (cropperInstance) {
            cropperInstance.destroy();
            cropperInstance = null;
        }

        currentImageElement = null;
        validateImageCount();
    }

    /**
     * Handle deleting image
     */
    function handleDeleteImage(imageData) {
        if (!imageData || !imageData.wrapper) return;

        imageData.wrapper.remove();
        uploadedImageData = uploadedImageData.filter(data => data !== imageData);
        validateImageCount();

        // Sync with external preview
        syncImagePreview();
    }

    /**
     * Validate image count (includes PDF files)
     */
    function validateImageCount() {
        const currentCount = uploadedImageData.length + uploadedPdfData.length;
        let errorMessage = '';
        let isButtonDisabled = true;

        // Use effective required count (shape-based or standard)
        const targetCount = shapeValidationEnabled && shapeRequirements.length > 0
            ? effectiveRequiredCount
            : requiredCount;

        if (currentCount < targetCount) {
            if (shapeValidationEnabled) {
                // Build shape-specific error message
                const shapeDetails = shapeRequirements.map(req =>
                    `${req.quantity_required} ${req.shape_type}`
                ).join(', ');
                errorMessage = `Please upload ${targetCount} images (${shapeDetails}) to proceed.`;
            } else {
                errorMessage = cpiu_params.error_less_images;
            }
            isButtonDisabled = true;
        } else if (currentCount > targetCount) {
            if (shapeValidationEnabled) {
                errorMessage = `You can only upload ${targetCount} images for the required shapes.`;
            } else {
                errorMessage = cpiu_params.error_more_images;
            }
            isButtonDisabled = true;
        } else {
            errorMessage = '';
            isButtonDisabled = false;
        }

        updateValidationError(errorMessage);

        if (addToCartButton) {
            addToCartButton.disabled = isButtonDisabled;
            addToCartButton.removeEventListener('click', handleAddToCartSubmission);

            if (!isButtonDisabled) {
                // Remove existing event listener before adding new one
                addToCartButton.removeEventListener('click', handleAddToCartSubmission);
                addToCartButton.addEventListener('click', handleAddToCartSubmission);
            }
        }
    }

    /**
     * Update validation error
     */
    function updateValidationError(message) {
        if (errorContainer) {
            errorContainer.textContent = message;
            if (message) {
                errorContainer.classList.add('cpiu-show');
            } else {
                errorContainer.classList.remove('cpiu-show');
            }
        }
    }

    /**
     * Show file type error notice
     */
    function showFileTypeError(filename) {
        const errorMessage = cpiu_params.error_file_type.replace('{filename}', filename);
        showNotice(errorMessage, 'error');
    }

    /**
     * Show file size error notice
     */
    function showFileSizeError(filename) {
        const errorMessage = cpiu_params.error_file_size.replace('{filename}', filename);
        showNotice(errorMessage, 'error');
    }

    /**
     * Show resolution error notice
     */
    function showResolutionError(errorMessage) {
        showNotice(errorMessage, 'error');
    }

    /**
     * Show notice message
     */
    function showNotice(message, type) {
        // Remove existing notices
        const existingNotices = document.querySelectorAll('.cpiu-notice');
        existingNotices.forEach(notice => notice.remove());

        // Create notice element
        const notice = document.createElement('div');
        notice.className = 'cpiu-notice cpiu-notice-' + type;
        notice.textContent = message;

        // Add animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);

        // Add to page
        document.body.appendChild(notice);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notice.parentNode) {
                notice.classList.add('cpiu-notice-exit');
                setTimeout(() => {
                    if (notice.parentNode) {
                        notice.remove();
                    }
                }, 300);
            }
        }, 5000);

        // Add click to dismiss
        notice.addEventListener('click', () => {
            if (notice.parentNode) {
                notice.classList.add('cpiu-notice-exit');
                setTimeout(() => {
                    if (notice.parentNode) {
                        notice.remove();
                    }
                }, 300);
            }
        });
    }

    /**
     * Handle add to cart submission.
     * Unified path: converts all cropped images to Blobs and sends them
     * together with any PDF files in a single multipart/form-data request
     * to the cpiu_upload_mixed_files endpoint.
     */
    async function handleAddToCartSubmission(event) {
        event.preventDefault();
        event.stopPropagation();

        // Use effective required count for shape validation
        const targetCount = shapeValidationEnabled && shapeRequirements.length > 0
            ? effectiveRequiredCount
            : requiredCount;

        const totalUploaded = uploadedImageData.length + uploadedPdfData.length;

        if (totalUploaded !== targetCount) {
            validateImageCount();
            alert(shapeValidationEnabled
                ? `Please upload ${targetCount} files to proceed.`
                : cpiu_params.error_less_images);
            return;
        }

        // Show upload loading modal
        if (uploadLoadingModal) {
            uploadLoadingModal.classList.add('show');
        }

        // Initialize progress tracking
        updateUploadProgress(0, cpiu_params.text_progress_preparing || 'Preparing files for upload...');

        if (addToCartButton) {
            addToCartButton.disabled = true;
        }

        const totalFiles = uploadedImageData.length + uploadedPdfData.length;

        // Update progress to show processing started
        const processingMessage = (cpiu_params.text_progress_processing || 'Processing {count} file{plural}...')
            .replace('{count}', totalFiles)
            .replace('{plural}', totalFiles > 1 ? 's' : '');
        updateUploadProgress(10, processingMessage);

        // Build a unified FormData with all files (images as Blobs, PDFs as File objects)
        const formData = new FormData();
        formData.append('action', 'cpiu_upload_mixed_files');
        formData.append('security', cpiu_params.nonce);
        formData.append('product_id', cpiu_params.product_id);

        if (cpiu_params.is_variable && addToCartButton) {
            const form = addToCartButton.closest('form.cart');
            if (form) {
                const variationInput = form.querySelector('input[name="variation_id"]');
                if (variationInput && variationInput.value) {
                    formData.append('variation_id', variationInput.value);
                }
            }
        }

        try {
            // Convert each cropped image Data URL → native Blob and append
            for (let i = 0; i < uploadedImageData.length; i++) {
                const dataUrl = uploadedImageData[i].currentSrc;
                const blob = await fetch(dataUrl).then(r => r.blob());
                const originalName = uploadedImageData[i].name || `image-${i}.jpg`;
                formData.append('cpiu_files[]', blob, originalName);
            }

            // Append PDF File objects directly (no conversion needed)
            uploadedPdfData.forEach((pdfData, i) => {
                formData.append('cpiu_files[]', pdfData.file, pdfData.name);
            });
        } catch (prepError) {
            updateUploadProgress(0, 'Error preparing files: ' + prepError.message, true);
            setTimeout(() => {
                if (uploadLoadingModal) uploadLoadingModal.classList.remove('show');
                if (addToCartButton) addToCartButton.disabled = false;
                alert('Error preparing files: ' + prepError.message);
            }, 2000);
            return;
        }

        // Simulate progress during upload
        let progressInterval = setInterval(() => {
            const currentProgress = getCurrentProgress();
            if (currentProgress < 80) {
                const uploadingMessage = cpiu_params.text_progress_uploading || 'Uploading files to server...';
                updateUploadProgress(currentProgress + 5, uploadingMessage);
            }
        }, 200);

        fetch(cpiu_params.ajax_url, {
            method: 'POST',
            body: formData
        })
            .then(response => {
                clearInterval(progressInterval);
                const serverProcessingMessage = cpiu_params.text_progress_server_processing || 'Processing server response...';
                updateUploadProgress(85, serverProcessingMessage);

                if (!response.ok) {
                    return response.text().then(text => {
                        const statusErrorMessage = (cpiu_params.text_ajax_status_error || 'HTTP error! Status: {status}, Response: {text}')
                            .replace('{status}', response.status)
                            .replace('{text}', text);
                        throw new Error(statusErrorMessage);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const finalizingMessage = cpiu_params.text_progress_finalizing || 'Finalizing upload...';
                    updateUploadProgress(95, finalizingMessage);

                    setTimeout(() => {
                        const completeMessage = cpiu_params.text_progress_complete || 'Upload complete! Redirecting to cart...';
                        updateUploadProgress(100, completeMessage);

                        setTimeout(() => {
                            if (data.cart_url || cpiu_params.cart_url) {
                                window.location.href = data.cart_url || cpiu_params.cart_url;
                            } else {
                                // Fallback: hide modal and re-enable button
                                if (uploadLoadingModal) {
                                    uploadLoadingModal.classList.remove('show');
                                }
                                if (addToCartButton) {
                                    addToCartButton.disabled = false;
                                }
                            }
                        }, 1000);
                    }, 500);
                } else {
                    clearInterval(progressInterval);
                    const serverMessage = data.data?.message || cpiu_params.text_unknown_error || 'Unknown server error.';
                    console.error('CPIU AJAX Error:', serverMessage, data);

                    const errorMessage = (cpiu_params.text_progress_error || 'Error: {message}')
                        .replace('{message}', serverMessage);
                    updateUploadProgress(0, errorMessage, true);

                    setTimeout(() => {
                        if (uploadLoadingModal) {
                            uploadLoadingModal.classList.remove('show');
                        }

                        if (addToCartButton) {
                            addToCartButton.disabled = false;
                        }

                        alert(cpiu_params.text_ajax_error.replace('{message}', serverMessage));
                    }, 2000);
                }
            })
            .catch(error => {
                clearInterval(progressInterval);
                console.error('CPIU Fetch Error:', error);

                const networkErrorMessage = (cpiu_params.text_progress_network_error || 'Network error: {message}')
                    .replace('{message}', error.message);
                updateUploadProgress(0, networkErrorMessage, true);

                setTimeout(() => {
                    if (uploadLoadingModal) {
                        uploadLoadingModal.classList.remove('show');
                    }

                    if (addToCartButton) {
                        addToCartButton.disabled = false;
                    }

                    alert(cpiu_params.text_ajax_network_error + ` (${error.message})`);
                }, 2000);
            });
    }


    /**
     * Update upload progress with visual feedback
     */
    function updateUploadProgress(percentage, message, isError = false) {
        if (uploadStatusText) {
            const color = isError ? '#e74c3c' : '#27ae60';
            uploadStatusText.innerHTML = `<p style="color: ${color}; margin: 0; font-weight: 500;">${message}</p>`;
        }

        // Update progress bar if it exists in the modal
        const modalProgressBar = document.querySelector('#cpiu-upload-loading .cpiu-progress-bar');
        if (modalProgressBar) {
            modalProgressBar.style.width = percentage + '%';
            modalProgressBar.style.backgroundColor = isError ? '#e74c3c' : (cpiu_params.progress_bar_color || '#007cba');
        }

        // Store current progress for interval tracking
        window.cpiuCurrentProgress = percentage;
    }

    /**
     * Get current progress percentage
     */
    function getCurrentProgress() {
        return window.cpiuCurrentProgress || 0;
    }

    // Apply dynamic colors
    applyDynamicColors();

    // Initial validation
    validateImageCount();

    // Handle variable products
    if (cpiu_params.is_variable && typeof jQuery !== 'undefined') {
        const uploadContainer = document.querySelector('.cpiu-image-preview-section');
        const updateUIForConfig = (config) => {
            if (config) {
                requiredCount = parseInt(config.image_count, 10) || 1;
                maxFileSize = parseInt(config.max_file_size, 10);
                allowedTypes = config.allowed_types || ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (shapeValidationEnabled && shapeRequirements.length > 0) {
                    effectiveRequiredCount = shapeRequirements.reduce((sum, req) => sum + parseInt(req.quantity_required, 10), 0);
                } else {
                    effectiveRequiredCount = requiredCount;
                }

                if (uploadContainer) uploadContainer.style.display = 'block';

                // Update file input validation params
                if (fileInput) {
                    fileInput.dataset.maxFiles = requiredCount;
                    fileInput.dataset.maxSize = maxFileSize;
                    fileInput.dataset.allowedTypes = JSON.stringify(allowedTypes);
                }

                validateImageCount();
            } else {
                if (uploadContainer) uploadContainer.style.display = 'none';
                requiredCount = 0;
                effectiveRequiredCount = 0;
                validateImageCount();
            }
        };

        jQuery('.variations_form').on('found_variation', function (event, variation) {
            const var_id = variation.variation_id;
            const variation_configs = cpiu_params.variation_configs || {};
            const active_config = variation_configs[var_id] || cpiu_params.parent_config;
            updateUIForConfig(active_config);
        });

        jQuery('.variations_form').on('reset_data hide_variation', function () {
            updateUIForConfig(cpiu_params.parent_config || null);
        });

        if (!jQuery('input[name="variation_id"]').val()) {
            updateUIForConfig(cpiu_params.parent_config || null);
        }
    }

    /**
     * Apply dynamic colors to UI elements
     */
    function applyDynamicColors() {
        if (typeof cpiu_params === 'undefined' || !cpiu_params.progress_bar_color) {
            return;
        }

        const color = cpiu_params.progress_bar_color;

        // Apply color to progress bar
        const progressBar = document.getElementById('cpiu-progress-bar');
        if (progressBar && !progressBar.closest('.elementor-widget-cpiu-product-image-upload')) {
            progressBar.style.backgroundColor = color;
        }

        // Apply color to upload button
        const uploadButton = document.getElementById('cpiu-open-upload-modal');
        if (uploadButton && !uploadButton.closest('.elementor-widget-cpiu-product-image-upload')) {
            uploadButton.style.backgroundColor = color;
            uploadButton.style.borderColor = color;
        }

        // Apply color to done button
        const doneButton = document.getElementById('cpiu-done-button');
        if (doneButton && !doneButton.closest('.elementor-widget-cpiu-product-image-upload')) {
            doneButton.style.backgroundColor = color;
            doneButton.style.borderColor = color;
        }

        // Apply color to save crop button
        const saveCropButton = document.getElementById('cpiu-save-cropped-image');
        if (saveCropButton && !saveCropButton.closest('.elementor-widget-cpiu-product-image-upload')) {
            saveCropButton.style.backgroundColor = color;
            saveCropButton.style.borderColor = color;
        }

        // Apply color to any elements with cpiu-dynamic-bg class
        const dynamicBgElements = document.querySelectorAll('.cpiu-dynamic-bg');
        dynamicBgElements.forEach(element => {
            if (!element.closest('.elementor-widget-cpiu-product-image-upload')) {
                element.style.setProperty('--cpiu-bg-color', color);
                element.style.backgroundColor = color;
            }
        });

        // Apply color to any elements with cpiu-dynamic-border class (like spinners)
        const dynamicBorderElements = document.querySelectorAll('.cpiu-dynamic-border');
        dynamicBorderElements.forEach(element => {
            if (!element.closest('.elementor-widget-cpiu-product-image-upload')) {
                element.style.setProperty('--cpiu-border-color', color);
                element.style.borderTopColor = color;
            }
        });
    }
});
