document.addEventListener("DOMContentLoaded", () => {
  // Mobile sidebar toggle
  const menuToggle = document.getElementById("menuToggle");
  const sidebar = document.getElementById("sidebar");
  const closeSidebar = document.getElementById("closeSidebar");

  if (menuToggle && sidebar && closeSidebar) {
    menuToggle.addEventListener("click", () => {
      sidebar.classList.add("active");
    });

    closeSidebar.addEventListener("click", () => {
      sidebar.classList.remove("active");
    });

    // Close sidebar when clicking outside
    document.addEventListener("click", (event) => {
      if (
        !sidebar.contains(event.target) &&
        !menuToggle.contains(event.target)
      ) {
        sidebar.classList.remove("active");
      }
    });
  }

  // Task details buttons
  const detailButtons = document.querySelectorAll(".task-details-btn");

  detailButtons.forEach((button) => {
    button.addEventListener("click", function () {
      const taskId = this.getAttribute("data-task-id");
      // In a real application, you would fetch task details from the server
      alert(`Viewing details for task ${taskId}`);
    });
  });

  // Make tables responsive with horizontal scroll on small screens
  const tables = document.querySelectorAll(".data-table");
  tables.forEach((table) => {
    const wrapper = document.createElement("div");
    wrapper.classList.add("table-scroll-wrapper");
    wrapper.style.overflowX = "auto";
    table.parentNode.insertBefore(wrapper, table);
    wrapper.appendChild(table);
  });

  // Auto-hide alerts after 5 seconds
  const alerts = document.querySelectorAll(".alert");
  if (alerts.length > 0) {
    setTimeout(() => {
      alerts.forEach((alert) => {
        alert.style.opacity = "0";
        setTimeout(() => {
          alert.style.display = "none";
        }, 300);
      });
    }, 5000);
  }
});
