# ============================================================
#  Generate Sample 5-Page ERP Policy Documents
#  Requires: pip install reportlab
# ============================================================

import os
import sys
from pathlib import Path

# Create sample_docs directory
docs_dir = Path(__file__).parent
os.makedirs(docs_dir, exist_ok=True)

try:
    from reportlab.lib.pagesizes import letter
    from reportlab.lib.units import inch
    from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, PageBreak
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.lib.colors import HexColor
except ImportError:
    print("ReportLab is required to run this script. Installing ReportLab...")
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "reportlab"])
    from reportlab.lib.pagesizes import letter
    from reportlab.lib.units import inch
    from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, PageBreak
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.lib.colors import HexColor

# Custom Page Template to draw Header/Footer and Page Numbers
def add_page_decorations(canvas, doc):
    canvas.saveState()
    # Top border & header
    canvas.setStrokeColor(HexColor("#8e44ad"))
    canvas.setLineWidth(1)
    canvas.line(54, 738, 558, 738)
    
    canvas.setFont('Helvetica-Bold', 8)
    canvas.setFillColor(HexColor("#5f2397"))
    canvas.drawString(54, 744, "BENITO-STEPHEN ERP ENTERPRISE SOLUTIONS")
    
    # Bottom border & footer
    canvas.setStrokeColor(HexColor("#cccccc"))
    canvas.line(54, 54, 558, 54)
    
    canvas.setFont('Helvetica', 8)
    canvas.setFillColor(HexColor("#777777"))
    canvas.drawString(54, 40, "Confidential Document — Internal Use Only")
    canvas.drawRightString(558, 40, f"Page {doc.page}")
    canvas.restoreState()

def generate_pdf(filename, title, pages_content):
    """
    Generate a 5-page PDF with professional styles.
    pages_content: list of 5 dictionaries containing:
                   'title': str,
                   'body': list of paragraphs (strings)
    """
    file_path = docs_dir / filename
    doc = SimpleDocTemplate(
        str(file_path),
        pagesize=letter,
        leftMargin=54,
        rightMargin=54,
        topMargin=72,
        bottomMargin=72
    )
    
    styles = getSampleStyleSheet()
    
    # Custom Styles
    title_style = ParagraphStyle(
        'DocTitle',
        parent=styles['Heading1'],
        fontName='Helvetica-Bold',
        fontSize=20,
        leading=24,
        textColor=HexColor('#8e44ad'),
        spaceAfter=15
    )
    
    heading_style = ParagraphStyle(
        'PageHeading',
        parent=styles['Heading2'],
        fontName='Helvetica-Bold',
        fontSize=14,
        leading=18,
        textColor=HexColor('#5f2397'),
        spaceBefore=15,
        spaceAfter=10
    )
    
    body_style = ParagraphStyle(
        'BodyText',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=10.5,
        leading=15,
        textColor=HexColor('#333333'),
        spaceAfter=12
    )

    story = []
    
    # Document Cover Header
    story.append(Paragraph(title, title_style))
    story.append(Spacer(1, 15))
    
    for i, page in enumerate(pages_content):
        # Page Subheading
        story.append(Paragraph(f"Section {i+1}: {page['title']}", heading_style))
        story.append(Spacer(1, 8))
        
        # Page Body Paragraphs
        for para_text in page['body']:
            story.append(Paragraph(para_text, body_style))
            story.append(Spacer(1, 4))
            
        # Insert PageBreak except on the last page to guarantee exactly 5 pages
        if i < 4:
            story.append(PageBreak())
            
    # Build Document
    doc.build(story, onFirstPage=add_page_decorations, onLaterPages=add_page_decorations)
    print(f"Generated 5-page PDF: {file_path}")

# ============================================================
#  Document Contents Definition (5 pages each)
# ============================================================

hr_policy_content = [
    {
        "title": "Welcome and Code of Conduct",
        "body": [
            "Welcome to the company! This HR Policy manual outlines the core guidelines, responsibilities, and expectations of all employees. Every team member is expected to maintain high levels of professionalism, integrity, and ethical conduct.",
            "Our company values diversity, inclusion, and mutual respect. Any form of harassment, discrimination, or hostile behavior will result in immediate disciplinary action up to termination. Employees are requested to report infractions directly to HR."
        ]
    },
    {
        "title": "Equal Opportunity and Workplace Environment",
        "body": [
            "We are committed to providing equal opportunities in all aspects of employment. Decisions regarding hiring, promotion, compensation, and training are made solely based on merit, skills, performance, and business requirements.",
            "We strive to maintain a safe, clean, and healthy workplace environment. Smoking, drug abuse, and alcohol consumption are strictly prohibited on office premises. Compliance with local health regulations is mandatory for all shifts."
        ]
    },
    {
        "title": "Working Hours and Attendance",
        "body": [
            "Standard core hours are Monday through Friday, 9:00 AM to 6:00 PM, including a 1-hour lunch break. Flexible arrangements are available upon approval by the department head and HR division.",
            "Employees are required to punch in and punch out using the ERP portal every work day to record attendance accurately. A delay of more than 15 minutes without prior notification is marked as a late arrival. Repeated late arrivals may impact performance ratings."
        ]
    },
    {
        "title": "Performance Reviews and Promotion",
        "body": [
            "Annual performance evaluations are conducted in December. Managers assess key performance indicators (KPIs), goals met, and team cooperation. A mid-year check-in is conducted in June to track progress and discuss adjustments.",
            "Promotions and merit-based salary increases are aligned with performance review outcomes. Employees showing consistent leadership qualities and technical excellence will be considered for high-priority advancement tracks."
        ]
    },
    {
        "title": "Disciplinary Procedures and Termination",
        "body": [
            "When issues of performance or conduct arise, HR applies a progressive disciplinary framework: first a verbal warning, followed by a written warning, suspension, and finally termination.",
            "In cases of severe misconduct (such as theft, fraud, or violence), the company reserves the right to terminate employment immediately without prior notice. Resignations require a formal notice period of 30 days."
        ]
    }
]

leave_policy_content = [
    {
        "title": "General Leave Rules",
        "body": [
            "All active full-time employees are entitled to standard paid leaves. The total paid leaves allowed per calendar year is 12 days for casual leaves and 12 days for sick leaves.",
            "Leaves must be applied in advance through the ERP Portal. Department heads must review and approve leave requests to ensure operational continuity. Unused casual leaves expire at the end of the year and cannot be carried forward."
        ]
    },
    {
        "title": "Casual Leaves (CL)",
        "body": [
            "Casual leaves are intended for personal reasons, family matters, or short vacations. Employees must request casual leave at least 3 business days in advance to allow workload planning.",
            "No more than 3 consecutive casual leaves can be taken without special written permission from the department head. Casual leaves cannot be clubbed with sick leaves or maternity/paternity leaves."
        ]
    },
    {
        "title": "Sick Leaves (SL)",
        "body": [
            "Sick leaves are designated for personal medical emergencies, illnesses, or recovery. In case of unexpected sickness, the employee must inform their supervisor before 10:00 AM on the day of absence.",
            "If sick leave exceeds 2 consecutive days, a valid medical certificate signed by a registered practitioner must be uploaded to the ERP portal upon return. Slander or misuse of sick leaves is considered a serious policy violation."
        ]
    },
    {
        "title": "Maternity and Paternity Leaves",
        "body": [
            "Female employees are entitled to 26 weeks of paid maternity leave for the first two children, which can be taken up to 8 weeks before the expected delivery date. Relevant medical documentation is required.",
            "Male employees are entitled to 15 days of paid paternity leave, which must be utilized within 6 months of the child's birth. Application must be submitted to HR at least 4 weeks prior to the start date."
        ]
    },
    {
        "title": "Leave Encashment and Approvals",
        "body": [
            "Only annual leaves (earned leaves) are eligible for encashment, up to a maximum of 30 days upon retirement or resignation. Casual and sick leaves are never eligible for cash payouts.",
            "HR administers final audits on leave balances. In cases where leaves are exhausted but additional time off is required, employees may request Leave Without Pay (LWP) subject to executive approvals."
        ]
    }
]

attendance_rules_content = [
    {
        "title": "Work Schedule and Shift Rules",
        "body": [
            "The standard workday consists of 8 working hours, excluding the lunch hour. Our core office shifts are: Shift A (8:00 AM - 5:00 PM), Shift B (9:00 AM - 6:00 PM), and Shift C (10:00 AM - 7:00 PM). Shift changes must be approved by management.",
            "Punctuality is crucial. Team members must be present at their workstations or logged in remotely by the start time of their assigned shift. Shift adherence is tracked automatically by the ERP system logs."
        ]
    },
    {
        "title": "Punch In and Out Protocols",
        "body": [
            "Every employee must record their daily attendance by clicking 'Punch In' at arrival and 'Punch Out' at departure. Forgetting to punch in/out will result in a missing attendance log.",
            "Regularizing attendance logs: If an employee misses a punch due to a technical error or offsite business meeting, they must submit an attendance regularization request within 48 hours to their supervisor."
        ]
    },
    {
        "title": "Break and Meal Periods",
        "body": [
            "Employees are allowed a 60-minute unpaid lunch break, which should be scheduled between 12:30 PM and 2:30 PM. Supervisors coordinate lunch timings to ensure team coverage.",
            "Two short 15-minute paid tea breaks are permitted—one in the morning session and one in the afternoon session. Abuse of break durations or leaving premises without informing supervisor is prohibited."
        ]
    },
    {
        "title": "Lateness, Half-days, and Absences",
        "body": [
            "Arrival after 10:15 AM (for the 9:00 AM shift) is officially flagged as a late arrival. Accumulating 3 late arrivals in a single calendar month will trigger a automatic deduction of a half-day casual leave.",
            "Leaving work before completing 4 hours will result in a half-day marked. Any absence without prior notice or approved leave for 5 consecutive days is treated as job abandonment and leads to termination."
        ]
    },
    {
        "title": "Overtime and Weekly Tracking",
        "body": [
            "Overtime (OT) must be pre-authorized in writing by the department head. OT is only applicable to non-executive staff performing critical maintenance or project tasks beyond standard hours.",
            "Employees must complete a minimum of 40 hours per week. The weekly hours metric is displayed on the employee dashboard. If a weekly hour total falls below 35 hours without leave, pay deductions will apply."
        ]
    }
]

it_security_content = [
    {
        "title": "Password and Access Controls",
        "body": [
            "Access to company networks, ERP databases, and servers is restricted to authorized personnel. Passwords must be at least 12 characters long and contain uppercase, lowercase, numbers, and special symbols.",
            "Passwords must be updated every 90 days. Sharing passwords or access badges is strictly prohibited. Employees must lock their computer screens (Windows key + L) whenever they step away from their desk."
        ]
    },
    {
        "title": "Device and Asset Security",
        "body": [
            "Company-provided laptops, tablets, and mobile phones are business assets. Personal use should be kept to a minimum. Installing unauthorized software, games, or torrent clients is blocked by security.",
            "Connecting unauthorized external media (USB drives, external hard disks) to company computers is prohibited to prevent malware injection. All company devices run mandatory antivirus software."
        ]
    },
    {
        "title": "Data Protection and Privacy",
        "body": [
            "Customer database entries, employee records, financial reports, and proprietary codes are classified as strictly confidential. Copying, exporting, or emailing confidential files is monitored by DLP systems.",
            "Any request for company data from external third parties must be immediately routed to the legal and compliance team. Breaching confidentiality leads to immediate termination and legal action."
        ]
    },
    {
        "title": "Safe Internet and Email Usage",
        "body": [
            "The company network is filtered to block dangerous, offensive, or malicious websites. Using VPNs or proxies to bypass content filters is a violation of security protocols.",
            "Phishing prevention: Do not click links or download attachments from unknown senders. Report suspicious emails using the 'Report Phishing' button on your email client interface."
        ]
    },
    {
        "title": "Security Incidents and Reporting",
        "body": [
            "If a laptop is lost, stolen, or compromised, the employee must report it to the IT Security Helpdesk within 2 hours. Remote wipe commands will be issued to protect data.",
            "If you notice suspicious activity on your desktop or database access logs, immediately disconnect from network and call support. Prompt reporting reduces system vulnerability."
        ]
    }
]

payroll_guide_content = [
    {
        "title": "Salary Structure and Basic Pay",
        "body": [
            "Employees receive their monthly salary based on their employment contract. The structure consists of Basic Salary, House Rent Allowance (HRA), Special Allowance, and Travel Allowance.",
            "Salaries are reviewed annually in conjunction with the performance review cycle. Details of salary structures are confidential and should not be discussed openly among colleagues."
        ]
    },
    {
        "title": "Deductions, Taxes, and PF",
        "body": [
            "Deductions from gross salary include Professional Tax, Income Tax (TDS), and Provident Fund (PF) contributions. Provident Fund contributions are matched by the company according to local labor laws.",
            "TDS deductions are calculated based on the investment declarations submitted by the employee in April. Failure to submit proof of investments by January will result in default tax calculations."
        ]
    },
    {
        "title": "Payment Schedule and Payslips",
        "body": [
            "Monthly salaries are credited directly to employees' designated bank accounts on the last business day of every month. Bank details must be kept updated in the ERP profile.",
            "Payslips are generated electronically and can be downloaded from the ERP portal's Payroll section on the 1st of the following month. For any discrepancies, contact the finance desk."
        ]
    },
    {
        "title": "Reimbursements and Allowances",
        "body": [
            "Business travel, client meals, and official utility expenses are eligible for reimbursement. Claims must be submitted with original receipts within 30 days of the expense.",
            "Late submissions will not be processed. Allowances for internet connectivity or work-from-home setups are paid out monthly upon department head approval."
        ]
    },
    {
        "title": "Payroll Queries and Disputes",
        "body": [
            "If you receive an incorrect payout, submit a ticket in the ERP support desk under 'Payroll Query'. Include your Employee ID, payout month, and details of the discrepancy.",
            "The payroll team resolves payroll disputes within 5 working days. Approved adjustments will be paid out as a special run or added to the subsequent month's salary cycle."
        ]
    }
]

# Generate all 5 files
if __name__ == "__main__":
    generate_pdf("HR_Policy.pdf", "HR Policy Manual 2026", hr_policy_content)
    generate_pdf("Leave_Policy.pdf", "Employee Leave Guidelines 2026", leave_policy_content)
    generate_pdf("Attendance_Rules.pdf", "Office Attendance Regulations 2026", attendance_rules_content)
    generate_pdf("IT_Security.pdf", "IT Security & Compliance Standards", it_security_content)
    generate_pdf("Payroll_Guide.pdf", "Payroll & Compensation Handbook", payroll_guide_content)
    print("\nAll 5-page sample documents generated successfully!")
