<?php

// Original sample wording with position-specific reference layouts.
return json_decode(<<<'JSON'
[
  {
    "industry": "Accounting & Finance",
    "template": "position-academic",
    "skills": "Financial reporting, Reconciliation, Excel, Budgeting",
    "education": "Accounting and finance",
    "bullets": [
      "Prepared reconciliations and resolved discrepancies before reporting deadlines.",
      "Maintained clear supporting schedules for reviews and management decisions."
    ],
    "roles": [
      "Accountant",
      "Staff Accountant",
      "Financial Advisor",
      "Financial Analyst",
      "Bank Teller"
    ]
  },
  {
    "industry": "Administration",
    "template": "position-classic",
    "skills": "Scheduling, Records management, Office coordination, Communication",
    "education": "Business administration",
    "bullets": [
      "Coordinated schedules and kept shared records accurate and accessible.",
      "Introduced a consistent handover checklist for recurring office tasks."
    ],
    "roles": [
      "Administrator",
      "Receptionist",
      "Office Assistant",
      "Secretary",
      "Executive Assistant",
      "Office Administrator",
      "Office Manager",
      "Personal Assistant",
      "Operations Manager",
      "Administrative Assistant",
      "Event Coordinator"
    ]
  },
  {
    "industry": "Business & Data",
    "template": "position-centred",
    "skills": "Data analysis, SQL, Reporting, Process mapping, Stakeholder communication",
    "education": "Business and data analysis",
    "bullets": [
      "Translated stakeholder questions into documented reporting requirements.",
      "Checked source data and explained findings in concise decision summaries."
    ],
    "roles": [
      "Business Analyst",
      "Data Engineer",
      "Data Analyst",
      "Business Data Analyst",
      "Data Scientist",
      "Data Entry Clerk",
      "Power BI Developer",
      "Logistics Coordinator",
      "General Manager",
      "Change Manager",
      "Business Intelligence Analyst",
      "Supply Chain Manager",
      "Property Manager",
      "Facilities Manager"
    ]
  },
  {
    "industry": "Software & IT",
    "template": "position-modern",
    "skills": "Software development, Git, Testing, SQL, Troubleshooting",
    "education": "Computing and information systems",
    "bullets": [
      "Delivered tested changes with documented acceptance criteria and review notes.",
      "Investigated defects and added checks to prevent recurring issues."
    ],
    "roles": [
      "Software Developer",
      "Java Developer",
      "SQL Developer",
      "Python Developer",
      "Android Developer",
      "Web Developer",
      "Full Stack Developer",
      "Programmer",
      "Front End Developer",
      "Network Engineer",
      "Computer Engineer",
      "Software Engineer",
      "Cybersecurity Analyst",
      "Backend Developer",
      "QA Engineer",
      "QA Analyst",
      "Software Tester",
      "System Administrator",
      "DevOps Engineer",
      "Machine Learning Engineer",
      "PHP Developer",
      "WordPress Developer",
      "Database Developer",
      "Service Desk Analyst",
      "Chief Technology Officer"
    ]
  },
  {
    "industry": "Customer Service",
    "template": "position-centred",
    "skills": "Customer support, Case management, Active listening, CRM, Problem solving",
    "education": "Customer service",
    "bullets": [
      "Resolved customer enquiries and recorded clear follow-up actions.",
      "Shared recurring issues with the team to improve support guidance."
    ],
    "roles": [
      "Customer Service Representative",
      "Customer Service Manager",
      "Customer Success Manager",
      "Retail Assistant"
    ]
  },
  {
    "industry": "Education & Research",
    "template": "position-academic",
    "skills": "Lesson planning, Assessment, Research, Communication, Record keeping",
    "education": "Education and research",
    "bullets": [
      "Prepared structured learning or research materials against agreed objectives.",
      "Recorded progress and used feedback to improve the next cycle of work."
    ],
    "roles": [
      "Teacher",
      "Elementary School Teacher",
      "High School Teacher",
      "Preschool Teacher",
      "Principal",
      "Research Assistant",
      "Research Associate",
      "Substitute Teacher",
      "Instructional Designer",
      "Graduate Student"
    ]
  },
  {
    "industry": "Engineering",
    "template": "position-academic",
    "skills": "Technical documentation, Design review, Quality assurance, Risk assessment",
    "education": "Engineering",
    "bullets": [
      "Reviewed technical requirements and documented design decisions for delivery.",
      "Investigated operational issues and tracked corrective actions to completion."
    ],
    "roles": [
      "Engineer",
      "Civil Engineer",
      "Electrical Engineer",
      "Entry-Level Mechanical Engineer",
      "Mechanical Engineer",
      "IT Engineer",
      "Process Engineer",
      "Quality Engineer",
      "Industrial Engineer",
      "Project Engineer",
      "Design Engineer",
      "Architect",
      "Manufacturing Engineer"
    ]
  },
  {
    "industry": "Healthcare",
    "template": "position-academic",
    "skills": "Patient support, Documentation, Confidentiality, Teamwork, Care coordination",
    "education": "Healthcare",
    "bullets": [
      "Maintained accurate records and followed established care protocols.",
      "Coordinated timely handovers and escalated concerns to the appropriate colleague."
    ],
    "roles": [
      "Healthcare Assistant",
      "Medical Assistant",
      "Medical Receptionist",
      "Phlebotomist",
      "Certified Nursing Assistant",
      "Dental Assistant"
    ]
  },
  {
    "industry": "Human Resources",
    "template": "position-minimal",
    "skills": "Recruitment, Onboarding, Employee records, Communication, HR systems",
    "education": "Human resources",
    "bullets": [
      "Coordinated recruitment activity and maintained consistent candidate records.",
      "Prepared onboarding materials and followed up on outstanding employee queries."
    ],
    "roles": [
      "Human Resources Specialist",
      "Recruiter",
      "HR Generalist",
      "Entry-Level HR Professional",
      "HR Assistant",
      "HR Director"
    ]
  },
  {
    "industry": "Legal",
    "template": "position-minimal",
    "skills": "Legal research, Drafting, Case organisation, Confidentiality, Attention to detail",
    "education": "Legal studies",
    "bullets": [
      "Organised case documents and checked references against source materials.",
      "Prepared clear research notes and tracked procedural deadlines."
    ],
    "roles": [
      "Attorney",
      "Legal Assistant",
      "Legal Secretary",
      "Paralegal",
      "Lawyer"
    ]
  },
  {
    "industry": "Marketing",
    "template": "position-modern",
    "skills": "Campaign planning, Analytics, Content strategy, Research, Communication",
    "education": "Marketing and communications",
    "bullets": [
      "Planned campaign activity against a clear audience and measurable objectives.",
      "Reviewed performance reports and documented improvements for subsequent campaigns."
    ],
    "roles": [
      "Marketing Specialist",
      "Digital Marketing Specialist",
      "Product Manager",
      "Account Manager",
      "Social Media Manager",
      "SEO Specialist",
      "Content Marketing Manager",
      "Email Marketing Specialist",
      "Brand Manager",
      "Marketing Assistant",
      "Marketing Director",
      "Communications Specialist",
      "Content Creator",
      "Content Strategist"
    ]
  },
  {
    "industry": "Nursing",
    "template": "position-modern",
    "skills": "Patient assessment, Care planning, Clinical documentation, Patient education",
    "education": "Nursing",
    "bullets": [
      "Documented patient observations and coordinated care with the clinical team.",
      "Supported patients with clear explanations and maintained safe handover records."
    ],
    "roles": [
      "Nurse",
      "Registered Nurse",
      "Nurse Practitioner"
    ]
  },
  {
    "industry": "Pharmacy",
    "template": "position-classic",
    "skills": "Medication safety, Inventory control, Patient counselling, Documentation",
    "education": "Pharmacy",
    "bullets": [
      "Checked medication records carefully and escalated discrepancies for review.",
      "Maintained stock records and supported clear communication with patients and colleagues."
    ],
    "roles": [
      "Pharmacist",
      "Clinical Pharmacist",
      "Pharmacy Technician"
    ]
  },
  {
    "industry": "Project Management",
    "template": "position-minimal",
    "skills": "Project planning, Risk tracking, Budget monitoring, Stakeholder reporting",
    "education": "Project management",
    "bullets": [
      "Maintained delivery plans with clear owners, milestones and dependencies.",
      "Tracked risks and communicated changes through regular stakeholder updates."
    ],
    "roles": [
      "Project Manager",
      "Project Coordinator",
      "IT Project Manager",
      "Construction Project Manager",
      "Senior Project Manager",
      "Scrum Master",
      "Agile Project Manager",
      "Technical Product Manager",
      "Program Manager",
      "Program Coordinator"
    ]
  },
  {
    "industry": "Sales",
    "template": "position-classic",
    "skills": "Customer relationships, Pipeline management, Negotiation, CRM, Reporting",
    "education": "Sales and business development",
    "bullets": [
      "Maintained an organised opportunity pipeline with timely follow-up actions.",
      "Prepared proposals around customer needs and recorded outcomes for review."
    ],
    "roles": [
      "Sales Representative",
      "Sales Manager",
      "Sales Associate",
      "Business Development Manager",
      "Leasing Consultant"
    ]
  },
  {
    "industry": "Writing",
    "template": "position-centred",
    "skills": "Editing, Research, Audience analysis, Content planning, Proofreading",
    "education": "Writing and communication",
    "bullets": [
      "Produced clear drafts from an agreed brief and checked claims against sources.",
      "Incorporated editorial feedback and maintained a consistent style across deliverables."
    ],
    "roles": [
      "Writer",
      "Copywriter",
      "Grant Writer",
      "Technical Writer",
      "Content Writer"
    ]
  },
  {
    "industry": "Design & Creative",
    "template": "position-classic",
    "skills": "Design research, Visual communication, Prototyping, Feedback, Portfolio development",
    "education": "Design and visual communication",
    "bullets": [
      "Turned an agreed creative brief into documented concepts and finished assets.",
      "Tested ideas with stakeholders and refined the work through structured feedback."
    ],
    "roles": [
      "Graphic Designer",
      "UX Designer",
      "Product Designer",
      "Animator",
      "Video Editor",
      "Web Designer",
      "3D Artist",
      "Photographer",
      "Producer"
    ]
  },
  {
    "industry": "Hospitality",
    "template": "position-classic",
    "skills": "Guest service, Teamwork, Attention to detail, Scheduling, Service standards",
    "education": "Hospitality",
    "bullets": [
      "Prepared service areas and supported a consistent guest experience.",
      "Handled requests promptly and maintained accurate handover information."
    ],
    "roles": [
      "Server",
      "Cashier",
      "Barista",
      "Esthetician"
    ]
  }
]
JSON, true, 512, JSON_THROW_ON_ERROR);
