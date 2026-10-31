(function($) {
    'use strict';

    const LinkCommAdmin = {
        
        init: function() {
            this.bindEvents();
            this.initWhitelistToggle();
            this.initRedisToggle();
            this.initCategorySelectAll();
            this.initRegenerateKey();
        },

        bindEvents: function() {
      
            $('#export-config-btn').on('click', this.handleExport);
            
            $('#import-config-btn').on('click', this.handleImport);
            
            $('#copy-export-config').on('click', this.handleCopyExport);
            
            $('#apply-import-config').on('click', this.handleApplyImport);
            
            $('[data-close-modal]').on('click', this.closeModal);
            
            $('.lci-modal').on('click', function(e) {
                if ($(e.target).hasClass('lci-modal')) {
                    LinkCommAdmin.closeModal();
                }
            });
            
            $('#clear-redis-cache').on('click', this.handleClearCache);
            
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    LinkCommAdmin.closeModal();
                }
            });
        },

        initWhitelistToggle: function() {
            $('#whitelist-toggle').on('change', function() {
                const content = $('#whitelist-content');
                if ($(this).is(':checked')) {
                    content.slideDown(300);
                } else {
                    content.slideUp(300);
                }
            });
        },

        initRedisToggle: function() {
            $('#redis-toggle').on('change', function() {
                const content = $('#redis-content');
                if ($(this).is(':checked')) {
                    content.css('display', 'flex').hide().slideDown(300);
                } else {
                    content.slideUp(300);
                }
            });
        },

        initCategorySelectAll: function() {
            $('#select-all-categories').on('change', function() {
                const isChecked = $(this).prop('checked');
                $('input[name="link_comm_interface_settings[selected_categories][]"]').prop('checked', isChecked);
            });
        },

        initRegenerateKey: function() {
            $('#regenerate-comm-key').on('click', function(e) {
                e.preventDefault();
                
                const newKey = prompt('请输入新的通讯密钥(留空则自动生成):');
                if (newKey === null) return;
                
                if (newKey === '') {
                 
                    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                    let randomKey = '';
                    for (let i = 0; i < 32; i++) {
                        randomKey += chars.charAt(Math.floor(Math.random() * chars.length));
                    }
                    $('#lci-comm-key').val(randomKey);
                } else {
                    $('#lci-comm-key').val(newKey);
                }
            });
        },

        handleExport: function() {
            $.ajax({
                url: linkCommAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'export_config',
                    nonce: linkCommAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const exportData = JSON.stringify(response.data, null, 2);
                        $('#export-config-textarea').val(exportData);
                        $('#export-modal').addClass('active');
                    } else {
                        LinkCommAdmin.showToast('导出失败', 'error');
                    }
                },
                error: function() {
                    LinkCommAdmin.showToast('请求失败，请重试', 'error');
                }
            });
        },

        handleImport: function() {
            $('#import-config-textarea').val('');
            $('#import-modal').addClass('active');
        },

        handleCopyExport: function() {
            const textarea = $('#export-config-textarea');
            textarea.select();
            
            try {
                document.execCommand('copy');
                LinkCommAdmin.showToast('配置已复制到剪贴板', 'success');
                LinkCommAdmin.closeModal();
            } catch (err) {
                LinkCommAdmin.showToast('复制失败，请手动复制', 'error');
            }
        },

        handleApplyImport: function() {
            const configData = $('#import-config-textarea').val().trim();
            
            if (!configData) {
                LinkCommAdmin.showToast('请输入配置数据', 'warning');
                return;
            }
            
            $.ajax({
                url: linkCommAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'import_config',
                    nonce: linkCommAjax.nonce,
                    config: configData
                },
                success: function(response) {
                    if (response.success) {
                        LinkCommAdmin.showToast('配置导入成功，页面将刷新', 'success');
                        LinkCommAdmin.closeModal();
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        LinkCommAdmin.showToast(response.data.message || '导入失败', 'error');
                    }
                },
                error: function() {
                    LinkCommAdmin.showToast('请求失败，请重试', 'error');
                }
            });
        },

        handleClearCache: function() {
            if (!confirm('确定要清除缓存吗？')) {
                return;
            }
            
            const btn = $('#clear-redis-cache');
            const originalText = btn.text();
            btn.text('清除中...').prop('disabled', true);
            
            $.ajax({
                url: linkCommAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'clear_redis_cache',
                    nonce: linkCommAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        LinkCommAdmin.showToast('缓存已清除', 'success');
                    } else {
                        LinkCommAdmin.showToast('清除失败', 'error');
                    }
                },
                error: function() {
                    LinkCommAdmin.showToast('请求失败，请重试', 'error');
                },
                complete: function() {
                    btn.text(originalText).prop('disabled', false);
                }
            });
        },

        closeModal: function() {
            $('.lci-modal').removeClass('active');
        },

        showToast: function(message, type) {
            const toast = $('#lci-toast');
            
            toast.removeClass('success error warning')
                .addClass(type)
                .text(message)
                .addClass('show');
            
            setTimeout(function() {
                toast.removeClass('show');
            }, 2500);
        }
    };

    $(document).ready(function() {
        LinkCommAdmin.init();
    });

})(jQuery);