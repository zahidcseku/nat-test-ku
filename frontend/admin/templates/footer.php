    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; padding: 24px; text-align: center; color: #718096; font-size: 13px;">
        <p>&copy; <?php echo date('Y'); ?> NAT-TEST Khulna Center. Internal use only.</p>
    </footer>

    <script>
        // CSRF token for AJAX requests
        window.csrfToken = '<?php echo e($csrfToken ?? ''); ?>';
    </script>
</body>
</html>
