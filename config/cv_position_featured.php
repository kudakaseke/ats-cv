<?php

// Eight position designs matched to the user-supplied reference screenshots.
return json_decode(<<<'JSON'
[
  {
    "id": "featured-software-developer",
    "title": "Software Developer",
    "industry": "Software & IT",
    "template": "position-software-developer",
    "aliases": [],
    "example": {
      "name": "Amelia David",
      "role": "Senior Software Developer",
      "email": "amelia.david@example.com",
      "website": "linkedin.com/in/amelia-david",
      "phone": "(555) 555-1234",
      "location": "Phoenix, Arizona",
      "summary": "Senior Software Developer with experience leading teams to deliver high-quality web and mobile applications. Successfully managed projects that increased application performance by 30%, boosted user engagement by 25%, and achieved an 80% client satisfaction rate. Expert in Agile methodologies, microservices architecture, and cloud-based infrastructure, consistently driving projects to exceed performance benchmarks.",
      "skills": "Java, Python, JavaScript, Visual Studio Code, Eclipse, HTML, CSS, React.js, SQL, MySQL, Linux, Analytical Thinking, Debugging, Troubleshooting, Problem-Solving, Communication, Teamwork, Time Management, Angular.js, Node.js, Agile, Scrum, JIRA, Test-Driven Development, Docker, Kubernetes, Leadership",
      "experience": [
        {
          "title": "Senior Software Developer",
          "company": "Appstra",
          "period": "Mar 2022 - Present",
          "location": "Phoenix, AZ",
          "description": "Led a team of 5 developers in the successful delivery of a complex web application, resulting in a 20% increase in user engagement within the first quarter post-launch.\nImplemented Agile methodologies, resulting in a 30% reduction in project timelines and a 15% increase in overall team productivity.\nDesigned and deployed a scalable microservices architecture, reducing server costs by 25% and improving system reliability by 20%.\nSpearheaded the migration of legacy systems to a modern cloud-based infrastructure, resulting in a 40% reduction in maintenance costs and a 50% improvement in response times.\nCollaborated with cross-functional teams to gather requirements and translate them into technical specifications."
        },
        {
          "title": "Software Developer",
          "company": "DevRocket",
          "period": "Jun 2013 - Mar 2022",
          "location": "Phoenix, AZ",
          "description": "Contributed to a mobile application that received a 4.5-star rating on both Google Play Store and Apple App Store.\nDesigned and implemented RESTful APIs to integrate the application with third-party services.\nOptimized database queries and implemented caching mechanisms to improve application performance.\nConducted code reviews and provided constructive feedback to team members."
        }
      ],
      "education": [
        {
          "school": "University of Arizona, Phoenix",
          "degree": "Bachelor of Science, Computer Science",
          "period": "",
          "location": ""
        }
      ],
      "courses": [
        {
          "title": "AWS Certified Developer - Associate",
          "institution": ""
        },
        {
          "title": "Scrum Master Certification (CSM)",
          "institution": ""
        }
      ]
    }
  },
  {
    "id": "featured-customer-service",
    "title": "Customer Service",
    "industry": "Customer Service",
    "template": "position-customer-service",
    "aliases": [
      "Customer Service Representative"
    ],
    "example": {
      "name": "David Kirby",
      "role": "Remote Customer Service Professional",
      "email": "david.kirby@example.com",
      "website": "linkedin.com/in/david-kirby",
      "phone": "(555) 555-1234",
      "location": "",
      "summary": "Tech-savvy and customer-focused professional with experience in delivering exceptional customer support in remote environments. Skilled in troubleshooting, problem-solving, and providing tailored solutions to diverse global customers. Strong written and verbal communication skills with a passion for educating and empowering users.",
      "skills": "Customer Service, Conflict Resolution, Active Listening, Customer Support, Issue Resolution, Written and Verbal Communication, Technical Troubleshooting, CRM Tools, Documentation Management, Empathy, Relationship-Building with Customers",
      "experience": [
        {
          "title": "Customer Support Specialist",
          "company": "Growth Bay SaaS Solutions",
          "period": "Jun 2021 - Present",
          "location": "Remote",
          "description": "Managed 50+ daily customer inquiries via email, phone, and live video sessions, ensuring a 95% first-response resolution rate.\nProvided technical troubleshooting and platform guidance, documenting all interactions in the company CRM system.\nConducted live project reviews and tailored guidance sessions, improving customer success rates.\nCollaborated with product and sales teams to address customer feedback."
        },
        {
          "title": "Technical Support Representative",
          "company": "Zodiac Software",
          "period": "Jan 2019 - May 2021",
          "location": "Remote",
          "description": "Resolved complex technical issues for a global customer base using phone and email support channels.\nLed live video demonstrations and screen-sharing sessions to guide customers through troubleshooting and product usage.\nCreated and maintained a database of common troubleshooting scenarios."
        },
        {
          "title": "Customer Service Representative",
          "company": "Solo Enterprises",
          "period": "Jun 2017 - Dec 2018",
          "location": "Remote",
          "description": "Responded to client inquiries, resolving 90% of issues within the first interaction.\nAssisted in training new team members on internal tools and customer service procedures."
        }
      ],
      "education": [
        {
          "school": "Community College",
          "degree": "Business and Customer Service",
          "period": "",
          "location": ""
        }
      ]
    }
  },
  {
    "id": "featured-network-engineer",
    "title": "Network Engineer",
    "industry": "Software & IT",
    "template": "position-network-engineer",
    "aliases": [],
    "example": {
      "name": "Jack Williams",
      "role": "Network Engineer",
      "email": "jack.williams@example.com",
      "website": "linkedin.com/in/jack-williams",
      "phone": "(555) 555-1234",
      "location": "Washington, D.C.",
      "summary": "Motivated recent graduate with a Bachelor's degree in Network Engineering possessing strong theoretical knowledge gained through coursework, hands-on projects, and volunteer experiences. Eager to apply skills and contribute to the success of a dynamic IT team.",
      "skills": "Network Design and Implementation, Routing and Switching, Network Security, Wireless Networks, Network Management, TCP/IP, DNS, DHCP, VLANs, VPNs, Routers, Switches, Firewalls, Cisco Packet Tracer, GNS3, Communication, Troubleshooting, Analytical Skills, Teamwork",
      "experience": [
        {
          "title": "IT Support Volunteer",
          "company": "Community Non-Profit",
          "period": "Jun 2022 - Present",
          "location": "Washington, D.C.",
          "description": "Provided technical support to staff members, troubleshooting hardware and software issues.\nAssisted in setting up and maintaining infrastructure for events and outreach programs.\nContributed to the development of IT policies and procedures."
        }
      ],
      "education": [
        {
          "school": "Howard University",
          "degree": "Bachelor of Science, Network Engineering",
          "period": "Sep 2020 - May 2024",
          "location": ""
        }
      ],
      "custom_sections": [
        {
          "title": "Projects",
          "content": "Capstone Project | Apr 2024\nDesigned and implemented a small-scale network for a fictional company, including configuring routers, switches, and firewalls to optimize performance and security.\n- Utilized Cisco Packet Tracer and GNS3 for network simulation and testing.\n- Documented network configurations and procedures for future reference.\n\nNetwork Security Project | Jan 2024\nCollaborated with a team to conduct vulnerability assessments and implement security measures for a local non-profit organization.\n- Utilized Wireshark, Nmap, and Nessus to identify and mitigate security threats.\n- Presented findings and recommendations to stakeholders."
        }
      ]
    }
  },
  {
    "id": "featured-medical-assistant",
    "title": "Medical Assistant",
    "industry": "Healthcare",
    "template": "position-medical-assistant",
    "aliases": [],
    "example": {
      "name": "Francis Matthews",
      "role": "Phlebotomist/Medical Assistant",
      "email": "francis.matthews@example.com",
      "website": "linkedin.com/in/francis-matthews",
      "phone": "(555) 555-1234",
      "location": "Atlanta, GA, USA",
      "summary": "Experienced medical professional with a strong background in phlebotomy, emergency room assistance, and combat medicine. Skilled in patient preparation, sample collection, clerical duties, and supporting healthcare providers to ensure efficient clinical operations.",
      "skills": "Patient Preparation, Specimen Collection, Phlebotomy, Vital Signs, Clinical Documentation, Electronic Health Records, Patient Support, Confidentiality",
      "experience": [
        {
          "title": "Phlebotomist/Medical Assistant",
          "company": "Hero Medical",
          "period": "Apr 2016 - Present",
          "location": "Atlanta",
          "description": "Responsible for a variety of venipuncture techniques involving the collection of blood specimens from patients for laboratory procedures and diagnosis.\nTake vital signs, including blood pressure and pulse, and collect specimens for testing.\nPerform clerical duties including preparing files and reports, monitoring and replenishing supplies and maintaining stock."
        },
        {
          "title": "Emergency Room Tech",
          "company": "EMedical Corp",
          "period": "Jan 2005 - Mar 2016",
          "location": "Atlanta",
          "description": "Assisted healthcare providers with minor procedures, patient preparation, and follow-up care.\nSupported patient care in emergency and treatment rooms.\nTranscribed medical information and physician orders in compliance with state and federal laws."
        },
        {
          "title": "Combat Medic",
          "company": "U.S. Army, USAREUR",
          "period": "Feb 1999 - Dec 2004",
          "location": "",
          "description": "Treated injured soldiers in routine patrol in Iraq.\nTrained new soldiers in medical skills and combat buddy aid skills.\nWorked with doctors, field hospitals, and a tank unit at a field medic station."
        }
      ],
      "education": [
        {
          "school": "Woodruff Medical Assistant Training School",
          "degree": "Diploma, Medical Assistant",
          "period": "Dec 2015",
          "location": ""
        },
        {
          "school": "United States Army",
          "degree": "Medical Training",
          "period": "Dec 1999",
          "location": ""
        }
      ]
    }
  },
  {
    "id": "featured-human-resources",
    "title": "Human Resources",
    "industry": "Human Resources",
    "template": "position-human-resources",
    "aliases": [
      "Human Resources Specialist"
    ],
    "example": {
      "name": "Jennifer Jobscan",
      "role": "Human Resources Specialist",
      "email": "jennifer.jobscan@example.com",
      "website": "linkedin.com/in/jennifer-jobscan",
      "phone": "(555) 555-1234",
      "location": "Seattle, WA, 98101, USA",
      "summary": "Experienced HR Professional with a strong background in recruitment, personnel management, and training. Proven ability to implement efficient hiring processes, build strong client relationships, and provide strategic career counselling. Committed to promoting professional development and employee retention.",
      "skills": "Recruitment, Onboarding, Employee Relations, Personnel Management, Career Advising, Training, Communication, HR Documentation",
      "experience": [
        {
          "title": "Owner",
          "company": "Recruiting by JJ",
          "period": "Aug 2016 - Present",
          "location": "Seattle",
          "description": "Oversee the human resource functions of companies, including posting opportunities, managing applicant and candidate flow, and addressing customer concerns.\nRespond to customer comments, questions, complaints, and praises.\nArrange fundraising opportunities for non-profit organizations.\nDevelop an internship program to attract potential marketing experience."
        },
        {
          "title": "Associate Director",
          "company": "Butler University, Career Center",
          "period": "Sep 2011 - Jul 2017",
          "location": "Indianapolis",
          "description": "Provided supervision for the Career Center team and student workers.\nOversaw Internship for Credit and related programs.\nImplemented a student employment system and training."
        },
        {
          "title": "Assistant Director/Advisor",
          "company": "Indiana University",
          "period": "Jul 2010 - Sep 2011",
          "location": "Indianapolis",
          "description": "Provided academic advising and career planning support.\nManaged an advising caseload to promote student retention.\nUtilized communication plans and feedback to improve advising services."
        }
      ],
      "education": [
        {
          "school": "University of Illinois",
          "degree": "Master of Science, Student Affairs and Higher Education",
          "period": "Dec 2010",
          "location": ""
        },
        {
          "school": "Butler University",
          "degree": "Bachelor of Science, Accounting & Legal Studies",
          "period": "Dec 2008",
          "location": ""
        }
      ]
    }
  },
  {
    "id": "featured-product-manager",
    "title": "Product Manager",
    "industry": "Marketing",
    "template": "position-product-manager",
    "aliases": [],
    "example": {
      "name": "Jessie Smith",
      "role": "Senior Product Manager",
      "email": "jessie.smith@example.com",
      "website": "linkedin.com/in/jessie-smith",
      "phone": "(202) 555-1234",
      "location": "New York, NY, 10001, USA",
      "summary": "Entrepreneurial leader with 12+ years of experience across a broad range of marketing disciplines and proven success in maximizing revenue opportunities in the U.S. and global markets. Recognized history of driving revenue through product adoption and motivating large cross-functional teams to produce high-level performance.",
      "experience": [
        {
          "title": "Senior Product Manager",
          "company": "NYC Healthcare",
          "period": "Dec 2021 - Present",
          "location": "New York City",
          "description": "Lead product definition, launch and long-term vision for initiatives across audiences and channels of a major healthcare brand.\nProvide commercial input and advice for new product introduction and life cycle management.\nDirect qualitative and quantitative market research to inform product direction.\nRepresent product at local, regional and national meetings."
        },
        {
          "title": "Senior Product Manager",
          "company": "Rochester Global Hospital",
          "period": "Nov 2019 - Nov 2021",
          "location": "Albany",
          "description": "Achieved sales growth objectives for the product portfolio by developing sales tools and improving customer relationships.\nSpearheaded the European launch of a coagulation assay.\nDeveloped a case study from assay data for marketing and sales training."
        },
        {
          "title": "Senior Marketing Manager",
          "company": "Clinical Health",
          "period": "Aug 2017 - Oct 2019",
          "location": "Philadelphia",
          "description": "Supported marketing strategy and the yearly media planning and management of marketing initiatives.\nLed cross-functional teams to improve patient experience and strengthen brand consistency."
        }
      ],
      "education": [
        {
          "school": "University of Pennsylvania",
          "degree": "Master of Business Administration, Marketing & Management",
          "period": "Dec 2015",
          "location": ""
        },
        {
          "school": "University of California",
          "degree": "Bachelor of Science, Business Administration",
          "period": "Dec 2013",
          "location": ""
        }
      ]
    }
  },
  {
    "id": "featured-nurse",
    "title": "Nurse",
    "industry": "Nursing",
    "template": "position-nurse",
    "aliases": [],
    "example": {
      "name": "Kathryn Hahn",
      "role": "Day Surgery Nurse",
      "email": "khahn@example.com",
      "website": "linkedin.com/in/kathryn-hahn",
      "phone": "(555) 555-1234",
      "location": "Los Angeles, CA, 90013, USA",
      "summary": "Experienced Day Surgery Nurse with a proven track record in pre-op and post-op patient care, team leadership, and process improvement. Skilled in patient education, treatment planning, and compliance with hospital protocols and safety standards.",
      "skills": "Patient Assessment, Patient Education, Treatment Planning, Clinical Documentation, Pre-operative Care, Post-operative Care, Team Leadership, Care Coordination",
      "experience": [
        {
          "title": "Day Surgery Nurse",
          "company": "UCLA Health",
          "period": "Jun 2013 - Present",
          "location": "Los Angeles",
          "description": "Prepared patients for surgery, administered pre-op medications and IVs, and coordinated with the operating room team.\nServed as a strong team leader in handling daily activities of the unit.\nManaged post-operative care of surgery patients.\nDemonstrated knowledge and skills necessary to provide appropriate patient care."
        },
        {
          "title": "Day Surgery/Pre-Op Nurse",
          "company": "WellStar",
          "period": "Jan 2011 - Jun 2013",
          "location": "Atlanta",
          "description": "Provided nursing care to surgical patients and developed individual care plans.\nCollaborated with other disciplines to ensure patient safety and facilitate achievement of patient outcomes.\nEducated patients and families on managing illness or injury.\nMonitored and managed pre-op IV therapy and administered medications before surgical procedures."
        }
      ],
      "education": [
        {
          "school": "West State University",
          "degree": "Bachelor of Science, Nursing",
          "period": "Dec 2010",
          "location": ""
        },
        {
          "school": "West State University",
          "degree": "Associate of Arts, Nursing",
          "period": "Dec 2008",
          "location": ""
        }
      ]
    }
  },
  {
    "id": "featured-project-manager",
    "title": "Project Manager",
    "industry": "Project Management",
    "template": "position-project-manager",
    "aliases": [],
    "example": {
      "name": "Melissa Bartlett",
      "role": "Assistant Project Manager",
      "email": "melissa.bartlett@example.com",
      "website": "linkedin.com/in/melissa-bartlett",
      "phone": "(555) 555-1234",
      "location": "Boise, ID",
      "summary": "Dedicated Assistant Project Manager with experience in the construction and IT project management industry, specializing in project planning, team coordination, and budget management. Proven track record of delivering projects up to 20% under budget and ahead of schedule, enhancing client satisfaction and profitability.",
      "skills": "Project Scheduling and Budgeting, Stakeholder Management, Risk Management, Agile and Waterfall Methodologies, Microsoft Project, Jira, AutoCAD, Resource Management, Quality Assurance and Control, Cost Estimation, Budget Management, Data Analysis, Reporting, Leadership, Adaptability, Problem-Solving, Negotiation, Creativity",
      "experience": [
        {
          "title": "Assistant Project Manager",
          "company": "Build Logic",
          "period": "Mar 2020 - Present",
          "location": "Boise",
          "description": "Assisted in managing construction projects valued at over $5 million, ensuring coordination between stakeholders, subcontractors and internal teams.\nPlayed a key role in a project that finished ahead of schedule, leading to a follow-up contract.\nImplemented a new cost-tracking system that reduced project expenses.\nLed weekly project meetings and reported directly to the Project Manager."
        },
        {
          "title": "Junior Project Manager Intern",
          "company": "Sync Tech",
          "period": "Jan 2018 - Feb 2020",
          "location": "Boise",
          "description": "Supported the delivery of software development projects valued at $3 million.\nContributed to workflow improvements and team productivity.\nManaged project documentation and maintained current compliance records.\nCollaborated with senior project managers to identify risks and develop mitigation strategies."
        }
      ],
      "education": [
        {
          "school": "University of Idaho",
          "degree": "Bachelor of Science, Project Management",
          "period": "Dec 2017",
          "location": ""
        }
      ],
      "courses": [
        {
          "title": "Certified Associate in Project Management (CAPM)",
          "institution": "Project Management Institute",
          "period": "Dec 2019"
        },
        {
          "title": "Agile Certified Practitioner (PMI-ACP)",
          "institution": "Project Management Institute",
          "period": "Jan 2021"
        }
      ]
    }
  }
]
JSON, true, 512, JSON_THROW_ON_ERROR);
